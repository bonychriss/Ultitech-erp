import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { formatInvoiceKpiHeadline } from '../utils/invoiceKpiTrace';

export default function InvoiceKpiTraceModal({ trace, onClose }) {
  const periods = Array.isArray(trace.periods) ? trace.periods : [];
  const entitySingular = trace.entitySingular || 'invoice';
  const entityPlural = trace.entityPlural || 'invoices';

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function handleKeyDown(event) {
      if (event.key === 'Escape') onClose();
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [onClose]);

  return createPortal(
    <div className="exp-desk-modal-backdrop" onClick={onClose} role="presentation">
      <div
        className="exp-desk-modal exp-desk-kpi-trace-modal qt-kpi-summary-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="inv-kpi-trace-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="exp-desk-modal-head">
          <div className="exp-desk-kpi-trace-head">
            <div>
              <h2 id="inv-kpi-trace-title" className="exp-desk-kpi-trace-title">{trace.title}</h2>
              <p className="exp-desk-kpi-trace-headline">{formatInvoiceKpiHeadline(trace)}</p>
            </div>
          </div>
          <button type="button" className="exp-desk-kpi-trace-close" onClick={onClose} aria-label="Close">
            <X className="w-5 h-5" />
          </button>
        </div>

        <div className="exp-desk-modal-body exp-desk-kpi-trace-body">
          {trace.confirmation && (
            <section className="exp-desk-kpi-trace-section exp-desk-kpi-trace-section--confirm">
              <h3 className="exp-desk-kpi-trace-section-title">Summary</h3>
              <p className="exp-desk-kpi-trace-confirmation">{trace.confirmation}</p>
              {trace.duration?.label && (
                <p className="qt-kpi-summary-duration">
                  <span className="qt-kpi-summary-duration-label">{trace.durationLabel || 'Invoice period'}:</span>
                  {' '}
                  {trace.duration.label}
                </p>
              )}
            </section>
          )}

          {periods.length > 0 && (
            <section className="exp-desk-kpi-trace-section">
              <h3 className="exp-desk-kpi-trace-section-title">Breakdown</h3>
              <div className="qt-kpi-summary-periods">
                {periods.map((period) => (
                  <div key={period.key} className="qt-kpi-summary-period">
                    <div className="qt-kpi-summary-period-head">
                      <span className="qt-kpi-summary-period-label">{period.label}</span>
                      {period.sublabel && (
                        <span className="qt-kpi-summary-period-sub">{period.sublabel}</span>
                      )}
                    </div>
                    <div className="qt-kpi-summary-period-metrics">
                      <span className="qt-kpi-summary-period-count">
                        {period.countDisplay}
                        {' '}
                        {Number(period.count) === 1 ? entitySingular : entityPlural}
                      </span>
                      <span className="qt-kpi-summary-period-amount">{period.amountDisplay}</span>
                    </div>
                  </div>
                ))}
              </div>
            </section>
          )}

          {trace.footnote && (
            <p className="exp-desk-kpi-trace-footnote">{trace.footnote}</p>
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
}
