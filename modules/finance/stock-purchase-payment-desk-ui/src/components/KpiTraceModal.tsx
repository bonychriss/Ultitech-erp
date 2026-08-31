import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import type { KpiTrace, KpiTraceKey } from '../types';
import { formatDate, formatMoney, balanceDueColorClass } from '../utils/format';
import { formatKpiHeadline } from '../utils/kpiTrace';
import { KPI_VISUALS } from '../utils/kpiVisuals';

interface KpiTraceModalProps {
  trace: KpiTrace;
  traceKey: KpiTraceKey;
  onClose: () => void;
}

export default function KpiTraceModal({ trace, traceKey, onClose }: KpiTraceModalProps) {
  const items = Array.isArray(trace.items) ? trace.items : [];
  const { Icon, iconClass } = KPI_VISUALS[traceKey];

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose();
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [onClose]);

  return createPortal(
    <div className="sppd-modal-backdrop" onClick={onClose} role="presentation">
      <div
        className="sppd-modal sppd-kpi-trace-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sppd-kpi-trace-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="sppd-modal-head">
          <div className="sppd-kpi-trace-head">
            <div className={`sppd-kpi-icon ${iconClass}`} aria-hidden="true">
              <Icon className="w-5 h-5" />
            </div>
            <div>
              <h2 id="sppd-kpi-trace-title" className="sppd-kpi-trace-title">{trace.title}</h2>
              <p className="sppd-kpi-trace-headline">{formatKpiHeadline(trace)}</p>
            </div>
          </div>
          <button type="button" className="sppd-kpi-trace-close" onClick={onClose} aria-label="Close">
            <X className="w-5 h-5" />
          </button>
        </div>

        <div className="sppd-modal-body sppd-kpi-trace-body">
          <section className="sppd-kpi-trace-section">
            <div className="sppd-kpi-trace-items-head">
              <h3 className="sppd-kpi-trace-section-title">
                Contributing records ({items.length})
              </h3>
            </div>

            {items.length === 0 ? (
              <p className="sppd-kpi-trace-empty">No records contributed to this KPI.</p>
            ) : (
              <div className="sppd-kpi-trace-table-wrap">
                <table className="sppd-kpi-trace-table">
                  <thead>
                    <tr>
                      <th>PO / Date</th>
                      <th>Supplier</th>
                      <th>PO total</th>
                      <th>Paid</th>
                      <th>Balance due</th>
                    </tr>
                  </thead>
                  <tbody>
                    {items.map((item) => (
                      <tr key={`${item.id ?? 0}-${item.poNumber}-${item.payeeName}`}>
                        <td>
                          <div className="sppd-kpi-trace-po-cell">
                            <span className="sppd-kpi-trace-po-no">{item.poNumber}</span>
                            {item.createdAt && (
                              <span className="sppd-kpi-trace-po-date">{formatDate(item.createdAt)}</span>
                            )}
                          </div>
                        </td>
                        <td className="sppd-kpi-trace-supplier">{item.payeeName || '-'}</td>
                        <td className="sppd-kpi-trace-amt">{formatMoney(item.amountToPay, item.currency)}</td>
                        <td className="sppd-kpi-trace-amt sppd-amt-paid">{formatMoney(item.amountPaid, item.currency)}</td>
                        <td className={`sppd-kpi-trace-amt ${balanceDueColorClass(item.balanceDue)}`}>
                          {formatMoney(item.balanceDue, item.currency)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </div>
      </div>
    </div>,
    document.body,
  );
}
