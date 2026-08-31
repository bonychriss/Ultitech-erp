function DocCardFoot({ onView, viewHref, downloadHref }) {
  return (
    <div className="vv-doc-card-foot">
      {viewHref ? (
        <a href={viewHref} target="_blank" rel="noopener noreferrer" className="vv-doc-card-action" aria-label="View document">
          <i className="fas fa-eye" aria-hidden="true" />
        </a>
      ) : (
        <button type="button" className="vv-doc-card-action" onClick={onView} aria-label="View document">
          <i className="fas fa-eye" aria-hidden="true" />
        </button>
      )}
      <span className="vv-doc-card-sep" aria-hidden="true" />
      <a href={downloadHref} download rel="noopener noreferrer" className="vv-doc-card-action" aria-label="Download document">
        <i className="fas fa-download" aria-hidden="true" />
      </a>
    </div>
  )
}

function DocCard({ iconClass, name, sub, onView, viewHref, downloadHref, onDelete, canDelete }) {
  const isImage = iconClass.includes('fa-file-image')
  return (
    <article className="vv-doc-card">
      {canDelete && onDelete ? (
        <button
          type="button"
          className="vv-doc-card-delete"
          onClick={onDelete}
          title="Delete document"
          aria-label="Delete document"
        >
          <i className="fas fa-trash" aria-hidden="true" />
        </button>
      ) : null}
      <div className="vv-doc-card-body">
        <div className={`vv-doc-icon${isImage ? ' vv-doc-icon--image' : ''}`}>
          <i className={`fas ${iconClass}`} aria-hidden="true" />
        </div>
        <div className="vv-doc-meta">
          <div className="vv-doc-name" title={name}>{name}</div>
          {sub ? <div className="vv-doc-sub">{sub}</div> : null}
        </div>
      </div>
      <DocCardFoot onView={onView} viewHref={viewHref} downloadHref={downloadHref} />
    </article>
  )
}

export default function DocumentsCard({ data, onPreview, onDeleteAttachment }) {
  const { attachments, salesOrderDocs, swiftProxy, documents, permissions } = data
  if (!documents.hasSupporting && !documents.mismatch) return null

  return (
    <section className="vv-card vv-card-docs documents-card no-print" id="attachments">
      <div className="vv-card-head">
        <h2 className="vv-card-title">Supporting Documents ({documents.headerCount})</h2>
      </div>
      <div className="vv-card-body">
        {documents.mismatch && (
          <div className="vv-alert vv-alert-warn">
            Expected <strong>{documents.declaredCount}</strong> supporting document{documents.declaredCount !== 1 ? 's' : ''}, but none were recorded for this voucher.
          </div>
        )}
        <div className="vv-doc-list">
          {salesOrderDocs.map((so) => (
            <DocCard
              key={`so-${so.id}`}
              iconClass="fa-file-pdf"
              name={`${so.orderNumber}.pdf`}
              sub="Sales Order"
              viewHref={so.pdfLink}
              downloadHref={so.pdfLink}
            />
          ))}

          {attachments.map((att) => (
            <DocCard
              key={att.id}
              iconClass={att.isImage ? 'fa-file-image' : 'fa-file-pdf'}
              name={att.name}
              sub={att.fileSizeLabel || att.typeLabel}
              onView={() => onPreview(att.proxyLink, 'supporting', att.isImage)}
              downloadHref={att.proxyLink}
              canDelete={permissions.canDeleteAttachment}
              onDelete={() => onDeleteAttachment(att.id)}
            />
          ))}

          {swiftProxy && (
            <DocCard
              iconClass="fa-file-pdf"
              name="SWIFT Payment Proof"
              sub="PDF"
              onView={() => onPreview(swiftProxy, 'swift', false)}
              downloadHref={swiftProxy}
            />
          )}
        </div>
      </div>
    </section>
  )
}
