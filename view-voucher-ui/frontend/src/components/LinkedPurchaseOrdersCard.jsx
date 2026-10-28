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

export default function LinkedPurchaseOrdersCard({ data }) {
  const docs = Array.isArray(data?.purchaseOrderDocs) ? data.purchaseOrderDocs : []
  if (docs.length === 0) return null

  return (
    <section className="vv-card vv-card-linked-pos no-print" aria-label="Linked purchase orders">
      <div className="vv-card-head">
        <h2 className="vv-card-title">
          Linked Purchase Order{docs.length === 1 ? '' : 's'}
          <span className="vv-gmail-count"> · {docs.length}</span>
        </h2>
      </div>
      <div className="vv-card-body vv-linked-po-list">
        {docs.map((po) => {
          const viewUrl = String(po.viewLink || '')
          const embedUrl = withPoEmbed(viewUrl)
          const meta = [
            po.supplierName ? `Supplier: ${po.supplierName}` : '',
            po.status ? `Status: ${po.status}` : '',
            po.createdBy ? `Created by ${po.createdBy}` : '',
          ].filter(Boolean)

          return (
            <article key={`linked-po-${po.id}`} className="vv-linked-po">
              <div className="vv-linked-po-head">
                <div className="vv-linked-po-titles">
                  <h3 className="vv-linked-po-title">{po.poNumber || `PO-${po.id}`}</h3>
                  {meta.length > 0 ? (
                    <p className="vv-linked-po-meta">{meta.join(' · ')}</p>
                  ) : null}
                </div>
                <div className="vv-linked-po-actions">
                  {viewUrl ? (
                    <a
                      className="vv-linked-po-btn"
                      href={viewUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      Open PO
                    </a>
                  ) : null}
                </div>
              </div>
              {embedUrl ? (
                <div className="vv-linked-po-frame-wrap">
                  <iframe
                    className="vv-linked-po-frame"
                    src={embedUrl}
                    title={`Purchase order ${po.poNumber || po.id}`}
                    loading="lazy"
                  />
                </div>
              ) : null}
            </article>
          )
        })}
      </div>
    </section>
  )
}
