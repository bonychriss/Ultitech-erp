import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Loader2, X } from 'lucide-react';
import { fetchKpiAiConfirmation } from '../api/revenueDesk';
import { formatKpiHeadline } from '../utils/kpiTrace';
import RevenueKpiIllustration from './RevenueKpiIllustration';

function formatCurrency(value) {
  const amount = Number(value) || 0;
  return `TZS ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(dateStr) {
  if (!dateStr) return '-';
  const normalized = dateStr.includes('T') ? dateStr : `${dateStr}T12:00:00`;
  return new Date(normalized).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function cellText(value) {
  const text = String(value ?? '').trim();
  return text || '-';
}

export default function RevenueKpiTraceModal({ trace, traceKey, onClose }) {
  const items = Array.isArray(trace.items) ? trace.items : [];
  const [confirmation, setConfirmation] = useState(trace.confirmation || '');
  const [viaAi, setViaAi] = useState(Boolean(trace.viaAi));
  const [aiLoading, setAiLoading] = useState(false);
  const [aiError, setAiError] = useState('');

  useEffect(() => {
    setConfirmation(trace.confirmation || '');
    setViaAi(Boolean(trace.viaAi));
    setAiError('');
    setAiLoading(false);
  }, [trace]);

  useEffect(() => {
    let cancelled = false;
    setAiLoading(true);
    setAiError('');

    fetchKpiAiConfirmation(traceKey, { trace })
      .then((result) => {
        if (cancelled) return;
        setConfirmation(result.confirmation || trace.confirmation || '');
        setViaAi(Boolean(result.viaAi));
      })
      .catch((err) => {
        if (cancelled) return;
        setAiError(err instanceof Error ? err.message : 'AI verification failed.');
      })
      .finally(() => {
        if (!cancelled) setAiLoading(false);
      });

    return () => { cancelled = true; };
  }, [traceKey, trace]);

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

  const contributionTotal = items.reduce((sum, item) => sum + (Number(item.contribution) || 0), 0);

  return createPortal(
    <div className="rev-kpi-trace-backdrop" onClick={onClose} role="presentation">
      <div
        className="rev-kpi-trace-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rev-kpi-trace-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="rev-kpi-trace-headbar">
          <div className="rev-kpi-trace-headcopy">
            <h2 id="rev-kpi-trace-title" className="rev-kpi-trace-title">{trace.title}</h2>
            <p className="rev-kpi-trace-headline">{formatKpiHeadline(trace)}</p>
          </div>
          <div className="rev-kpi-trace-illus-wrap">
            <RevenueKpiIllustration traceKey={trace.illustration || traceKey} />
          </div>
          <button type="button" className="rev-kpi-trace-close" onClick={onClose} aria-label="Close">
            <X size={18} />
          </button>
        </div>

        <div className="rev-kpi-trace-body">
          <section className="rev-kpi-trace-section rev-kpi-trace-section--confirm">
            <div className="rev-kpi-trace-confirm-head">
              <h3 className="rev-kpi-trace-section-title">Confirmation</h3>
              {aiLoading && (
                <span className="rev-kpi-trace-ai-badge rev-kpi-trace-ai-badge--loading">
                  <Loader2 size={12} className="rev-kpi-trace-spin" aria-hidden="true" />
                  Verifying with AI
                </span>
              )}
              {!aiLoading && viaAi && (
                <span className="rev-kpi-trace-ai-badge">AI verified</span>
              )}
            </div>
            <p className="rev-kpi-trace-confirmation">
              {confirmation || 'Checking this KPI with AI...'}
            </p>
            {aiError ? <p className="rev-kpi-trace-ai-error" role="alert">{aiError}</p> : null}
          </section>

          <section className="rev-kpi-trace-section">
            <h3 className="rev-kpi-trace-section-title">
              Contributing records ({items.length})
            </h3>

            {items.length === 0 ? (
              <p className="rev-kpi-trace-empty">No records contributed to this KPI.</p>
            ) : (
              <div className="rev-kpi-trace-table-wrap">
                <table className="rev-kpi-trace-table">
                  <thead>
                    <tr>
                      <th>Voucher / Date</th>
                      <th>Customer</th>
                      <th>Type</th>
                      <th>Status</th>
                      <th>Contribution</th>
                    </tr>
                  </thead>
                  <tbody>
                    {items.slice(0, 100).map((item) => (
                      <tr key={`${item.id}-${item.voucherNumber}-${item.date}`}>
                        <td>
                          <div className="rev-kpi-trace-voucher-cell">
                            <span className="rev-kpi-trace-voucher">{cellText(item.voucherNumber)}</span>
                            {item.date ? (
                              <span className="rev-kpi-trace-date">{formatDate(item.date)}</span>
                            ) : null}
                          </div>
                        </td>
                        <td>{cellText(item.customer)}</td>
                        <td>{cellText(item.type)}</td>
                        <td>{cellText(item.status)}</td>
                        <td className="rev-kpi-trace-amt">{formatCurrency(item.contribution)}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="rev-kpi-trace-total-row">
                      <td colSpan={4} className="rev-kpi-trace-total-label">
                        Total{items.length > 100 ? ` (first 100 of ${items.length})` : ''}
                      </td>
                      <td className="rev-kpi-trace-amt rev-kpi-trace-total-amt">
                        {formatCurrency(contributionTotal)}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            )}

            {trace.footnote ? (
              <p className="rev-kpi-trace-footnote">{trace.footnote}</p>
            ) : null}
          </section>
        </div>
      </div>
    </div>,
    document.body,
  );
}
