function statusClass(status) {
  switch (status) {
    case 'complete':
    case 'pass':
      return 'ov-po-info-status--ok';
    case 'partial':
    case 'warn':
      return 'ov-po-info-status--warn';
    case 'over':
    case 'fail':
      return 'ov-po-info-status--bad';
    default:
      return 'ov-po-info-status--muted';
  }
}

function statusLabel(status) {
  switch (status) {
    case 'complete': return 'Received';
    case 'partial': return 'Partial';
    case 'over': return 'Over-received';
    case 'pending': return 'Not received';
    case 'pass': return 'Verified';
    case 'warn': return 'Review';
    case 'fail': return 'Issue found';
    default: return status || '-';
  }
}

function formatQty(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0';
  return n.toLocaleString(undefined, { maximumFractionDigits: 2 });
}

export default function PoInfoModal({ open, loading, error, data, onClose, onRefresh }) {
  if (!open) return null;

  const summary = data?.summary || {};
  const verification = data?.verification || {};
  const ai = verification.ai || {};
  const lines = data?.lines || [];

  return (
    <div className="ov-modal-backdrop ov-no-print" role="dialog" aria-modal="true" aria-labelledby="po-info-title">
      <div className="ov-modal-card ov-po-info-modal">
        <div className="ov-modal-header">
          <div>
            <h5 id="po-info-title">PO Info - Receipt and Stock</h5>
            {data?.po_number ? (
              <div className="ov-po-info-subtitle">
                {data.po_number}
                {' | '}
                {data.po_status || '-'}
              </div>
            ) : null}
          </div>
          <button type="button" className="ov-modal-close" onClick={onClose} aria-label="Close">&times;</button>
        </div>

        <div className="ov-modal-body ov-po-info-body">
          {loading ? (
            <div className="ov-boot-loading">
              <span className="ov-boot-spinner" aria-hidden="true" />
              <span>Loading receipt info...</span>
            </div>
          ) : null}

          {!loading && error ? (
            <div className="ov-flash ov-flash-error" role="alert">{error}</div>
          ) : null}

          {!loading && !error && data ? (
            <>
              <div className="ov-po-info-summary-grid">
                <div className="ov-po-info-summary-card">
                  <div className="ov-po-info-summary-label">Receipt score</div>
                  <div className="ov-po-info-summary-value">{summary.score ?? 0}%</div>
                </div>
                <div className="ov-po-info-summary-card">
                  <div className="ov-po-info-summary-label">Lines received</div>
                  <div className="ov-po-info-summary-value">
                    {summary.complete_lines ?? 0}/{summary.total_lines ?? 0}
                  </div>
                </div>
                <div className="ov-po-info-summary-card">
                  <div className="ov-po-info-summary-label">Verification</div>
                  <div className={`ov-po-info-pill ${statusClass(summary.overall_status)}`}>
                    {statusLabel(summary.overall_status)}
                  </div>
                </div>
              </div>

              <div className="ov-po-info-ai">
                <div className="ov-po-info-ai-head">
                  <i className="fas fa-robot" aria-hidden="true" />
                  <strong>AI verification</strong>
                  {ai.via_ai ? <span className="ov-po-info-ai-badge">AI</span> : <span className="ov-po-info-ai-badge muted">Rules</span>}
                </div>
                <p>{ai.summary || verification.rule_summary || 'No verification summary available.'}</p>
              </div>

              <div className="ov-po-info-table-wrap">
                <table className="ov-po-info-table">
                  <thead>
                    <tr>
                      <th>Product</th>
                      <th className="num">Ordered</th>
                      <th className="num">Received</th>
                      <th className="num">Stock before</th>
                      <th className="num">Stock after</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {lines.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="ov-po-info-empty">No line items found.</td>
                      </tr>
                    ) : lines.map((line) => (
                      <tr key={line.po_item_id || line.item_id}>
                        <td>
                          <div className="ov-po-info-product">{line.product_name}</div>
                          {line.sku ? <div className="ov-po-info-sku">{line.sku}</div> : null}
                        </td>
                        <td className="num">{formatQty(line.qty_ordered)}</td>
                        <td className="num">{formatQty(line.qty_received)}</td>
                        <td className="num">{formatQty(line.stock_before)}</td>
                        <td className="num">{formatQty(line.stock_after)}</td>
                        <td>
                          <span className={`ov-po-info-pill ${statusClass(line.receipt_status)}`}>
                            {statusLabel(line.receipt_status)}
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {lines.some((line) => (line.checks || []).some((c) => !c.ok)) ? (
                <div className="ov-po-info-checks">
                  <div className="ov-po-info-checks-title">Line checks</div>
                  {lines.map((line) => (
                    (line.checks || []).filter((c) => !c.ok).map((check) => (
                      <div key={`${line.po_item_id}-${check.key}`} className="ov-po-info-check-row warn">
                        <i className="fas fa-exclamation-triangle" aria-hidden="true" />
                        <span>
                          <strong>{line.product_name}:</strong>
                          {' '}
                          {check.label}
                          {check.detail ? `: ${check.detail}` : ''}
                        </span>
                      </div>
                    ))
                  ))}
                </div>
              ) : null}
            </>
          ) : null}
        </div>

        <div className="ov-modal-footer">
          {data?.urls?.receipt_audit ? (
            <a href={data.urls.receipt_audit} className="ov-btn ov-btn-secondary" target="_blank" rel="noreferrer">
              Full audit
            </a>
          ) : null}
          <button type="button" className="ov-btn ov-btn-secondary" onClick={onRefresh} disabled={loading}>
            Refresh
          </button>
          <button type="button" className="ov-btn ov-btn-primary" onClick={onClose}>Close</button>
        </div>
      </div>
    </div>
  );
}
