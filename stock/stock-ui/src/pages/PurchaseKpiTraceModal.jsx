import React, { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { HiOutlineXMark } from 'react-icons/hi2';

function cellText(value) {
  const text = String(value ?? '').trim();
  if (!text || text === '\u2014' || text === '\u2013' || text === '\uFFFD') return '-';
  return text;
}

function formatDate(dateStr) {
  if (!dateStr) return '-';
  const normalized = String(dateStr).includes('T') ? dateStr : `${dateStr}T12:00:00`;
  const t = Date.parse(normalized);
  if (Number.isNaN(t)) return '-';
  return new Date(t).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

export default function PurchaseKpiTraceModal({ trace, onClose }) {
  const items = Array.isArray(trace?.items) ? trace.items : [];
  const totalAmount = items.reduce((sum, row) => sum + (Number(row.amount) || 0), 0);

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const onKey = (e) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', onKey);
    };
  }, [onClose]);

  if (!trace) return null;

  return createPortal(
    <div className="po-desk-modal-backdrop" onClick={onClose} role="presentation">
      <div
        className="po-desk-modal po-desk-kpi-trace-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="po-kpi-trace-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="po-desk-modal-head">
          <div className="po-desk-kpi-trace-head">
            <h2 id="po-kpi-trace-title" className="po-desk-kpi-trace-title">
              {trace.title}
            </h2>
            <p className="po-desk-kpi-trace-headline">{trace.headline}</p>
          </div>
          <button type="button" className="po-desk-kpi-trace-close" onClick={onClose} aria-label="Close">
            <HiOutlineXMark size={20} />
          </button>
        </div>

        <div className="po-desk-modal-body po-desk-kpi-trace-body">
          {trace.confirmation ? (
            <section className="po-desk-kpi-trace-section">
              <h3 className="po-desk-kpi-trace-section-title">Confirmation</h3>
              <p className="po-desk-kpi-trace-confirmation">{trace.confirmation}</p>
            </section>
          ) : null}

          <section className="po-desk-kpi-trace-section">
            <div className="po-desk-kpi-trace-items-head">
              <h3 className="po-desk-kpi-trace-section-title">
                Contributing records ({trace.totalCount ?? items.length})
              </h3>
            </div>

            {items.length === 0 ? (
              <p className="po-desk-kpi-trace-empty">No purchase orders contributed to this KPI.</p>
            ) : (
              <div className="po-desk-kpi-trace-table-wrap">
                <table className="po-desk-kpi-trace-table">
                  <thead>
                    <tr>
                      <th>PO / Date</th>
                      <th>Supplier</th>
                      <th>Type</th>
                      <th>Status</th>
                      <th>Amount</th>
                    </tr>
                  </thead>
                  <tbody>
                    {items.map((item) => (
                      <tr key={item.id || `${item.purchaseNo}-${item.date}`}>
                        <td>
                          <div className="po-desk-kpi-trace-exp-cell">
                            <span className="po-desk-kpi-trace-exp-no">{cellText(item.purchaseNo)}</span>
                            {item.date ? (
                              <span className="po-desk-kpi-trace-exp-date">{formatDate(item.date)}</span>
                            ) : null}
                          </div>
                        </td>
                        <td className="po-desk-kpi-trace-payee">{cellText(item.supplier)}</td>
                        <td>{cellText(item.typeLabel)}</td>
                        <td>{cellText(item.statusLabel)}</td>
                        <td className="po-desk-kpi-trace-amt">{cellText(item.amountDisplay)}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="po-desk-kpi-trace-total-row">
                      <td colSpan={4} className="po-desk-kpi-trace-total-label">
                        Total
                      </td>
                      <td className="po-desk-kpi-trace-amt po-desk-kpi-trace-total-amt">
                        {trace.totalDisplay ||
                          `${trace.currencySymbol || ''}${totalAmount.toLocaleString('en', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2,
                          })}`}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            )}

            {trace.footnote ? <p className="po-desk-kpi-trace-footnote">{trace.footnote}</p> : null}
          </section>
        </div>
      </div>
    </div>,
    document.body
  );
}
