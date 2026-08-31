import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { formatDateTime, formatMoney, typeBadgeClass } from '../utils/format';
import { formatKpiHeadline, type KpiTrace } from '../utils/kpiTrace';

type KpiTraceModalProps = {
  trace: KpiTrace;
  onClose: () => void;
};

function cellText(value: string): string {
  const text = String(value ?? '').trim();
  if (!text || text === '' || text === '' || text === '\uFFFD') return '-';
  return text;
}

export default function KpiTraceModal({ trace, onClose }: KpiTraceModalProps) {
  const items = Array.isArray(trace.items) ? trace.items : [];
  const totalContribution = items.reduce((sum, item) => sum + item.amount, 0);

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
    <div className="tl-modal-backdrop" onClick={onClose} role="presentation">
      <div
        className="tl-modal tl-kpi-trace-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="tl-kpi-trace-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="tl-modal-head">
          <div className="tl-kpi-trace-head">
            <div>
              <h2 id="tl-kpi-trace-title" className="tl-kpi-trace-title">
                {trace.title}
              </h2>
              <p className="tl-kpi-trace-headline">{formatKpiHeadline(trace)}</p>
            </div>
          </div>
          <button type="button" className="tl-kpi-trace-close" onClick={onClose} aria-label="Close">
            <X className="w-5 h-5" />
          </button>
        </div>

        <div className="tl-modal-body tl-kpi-trace-body">
          <section className="tl-kpi-trace-section tl-kpi-trace-section--confirm">
            <h3 className="tl-kpi-trace-section-title">Confirmation</h3>
            <p className="tl-kpi-trace-confirmation">{trace.confirmation}</p>
          </section>

          <section className="tl-kpi-trace-section">
            <div className="tl-kpi-trace-items-head">
              <h3 className="tl-kpi-trace-section-title">Contributing records ({items.length})</h3>
            </div>

            {items.length === 0 ? (
              <p className="tl-kpi-trace-empty">No records contributed to this KPI.</p>
            ) : (
              <div className="tl-kpi-trace-table-wrap">
                <table className="tl-kpi-trace-table">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Account</th>
                      <th>Description</th>
                      <th>Reference</th>
                      <th>Type</th>
                      <th className="tl-kpi-trace-amt">Amount</th>
                    </tr>
                  </thead>
                  <tbody>
                    {items.map((item) => (
                      <tr key={item.id}>
                        <td className="tl-kpi-trace-date">{formatDateTime(item.date)}</td>
                        <td className="tl-kpi-trace-account">{cellText(item.account)}</td>
                        <td>{cellText(item.description)}</td>
                        <td>{cellText(item.reference)}</td>
                        <td>
                          <span className={typeBadgeClass(item.typeClass)}>{item.typeLabel}</span>
                        </td>
                        <td className="tl-kpi-trace-amt">{item.amountDisplay}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="tl-kpi-trace-total-row">
                      <td colSpan={5} className="tl-kpi-trace-total-label">
                        Loaded total
                      </td>
                      <td className="tl-kpi-trace-amt tl-kpi-trace-total-amt">
                        {formatMoney(totalContribution)}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            )}

            {trace.footnote && <p className="tl-kpi-trace-footnote">{trace.footnote}</p>}
          </section>
        </div>
      </div>
    </div>,
    document.body,
  );
}
