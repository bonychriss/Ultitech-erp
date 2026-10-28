import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Loader2, X } from 'lucide-react';
import { fetchKpiAiConfirmation } from '../api/expensesDesk';
import { formatKpiHeadline } from '../utils/kpiTrace';

function formatCurrency(value, currencyCode) {
  const code = String(currencyCode || 'TZS').replace(/^TSh$/i, 'TZS');
  const amount = Number(value) || 0;
  return `${code} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
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
  if (!text || text === '\u2014' || text === '\u2013' || text === '\uFFFD') return '-';
  return text;
}

function normalizeCurrencyCode(currency) {
  return String(currency || 'TZS').replace(/^TSh$/i, 'TZS').trim() || 'TZS';
}

function sumAmountsByCurrency(items, field = 'amount') {
  const totals = new Map();
  for (const item of items) {
    const code = normalizeCurrencyCode(item.currency);
    totals.set(code, (totals.get(code) || 0) + (Number(item[field] ?? item.amount) || 0));
  }
  return [...totals.entries()].sort(([a], [b]) => a.localeCompare(b));
}

const EXPENSE_AI_KEYS = new Set([
  'postedThisMonth',
  'monthlySpend',
  'totalRecords',
  'listedNow',
  'totalExclusive',
  'inputVat',
  'totalInclusive',
  'totalBank',
  'totalCash',
  'totalPayables',
]);

export default function ExpenseKpiTraceModal({
  trace,
  traceKey,
  filters = {},
  listedCount = 0,
  purchasesDesk = false,
  onClose,
}) {
  const items = Array.isArray(trace.items) ? trace.items : [];
  const amountField = purchasesDesk ? 'contribution' : 'amount';
  const amountTotals = sumAmountsByCurrency(items, amountField);
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
    // Purchases KPIs use client-side confirmation; expense AI endpoint only
    // accepts a fixed allow-list and returns "Invalid KPI key" otherwise.
    if (purchasesDesk || !EXPENSE_AI_KEYS.has(traceKey)) {
      setAiLoading(false);
      setAiError('');
      setConfirmation(trace.confirmation || '');
      setViaAi(false);
      return undefined;
    }

    let cancelled = false;
    setAiLoading(true);
    setAiError('');

    fetchKpiAiConfirmation(traceKey, { listedCount, filters })
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

    return () => {
      cancelled = true;
    };
  }, [traceKey, listedCount, filters, trace.confirmation, purchasesDesk]);

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

  const refLabel = purchasesDesk ? 'Purchase / Date' : 'Expense / Date';

  return createPortal(
    <div className="exp-desk-modal-backdrop" onClick={onClose} role="presentation">
      <div
        className={`exp-desk-modal exp-desk-kpi-trace-modal${purchasesDesk ? ' exp-desk-kpi-trace-modal--purchases' : ''}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby="exp-kpi-trace-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="exp-desk-modal-head">
          <div className="exp-desk-kpi-trace-head">
            <div>
              <h2 id="exp-kpi-trace-title" className="exp-desk-kpi-trace-title">{trace.title}</h2>
              <p className="exp-desk-kpi-trace-headline">{formatKpiHeadline(trace)}</p>
            </div>
          </div>
          <button type="button" className="exp-desk-kpi-trace-close" onClick={onClose} aria-label="Close">
            <X className="w-5 h-5" />
          </button>
        </div>

        <div className="exp-desk-modal-body exp-desk-kpi-trace-body">
          {(confirmation || aiLoading) && (
            <section className="exp-desk-kpi-trace-section exp-desk-kpi-trace-section--confirm">
              <div className="exp-desk-kpi-trace-confirm-head">
                <h3 className="exp-desk-kpi-trace-section-title">Confirmation</h3>
                {aiLoading && (
                  <span className="exp-desk-kpi-trace-ai-badge exp-desk-kpi-trace-ai-badge--loading">
                    <Loader2 className="w-3 h-3 animate-spin" aria-hidden="true" />
                    Verifying with AI
                  </span>
                )}
                {!aiLoading && viaAi && (
                  <span className="exp-desk-kpi-trace-ai-badge">AI verified</span>
                )}
              </div>
              <p className="exp-desk-kpi-trace-confirmation">
                {confirmation || 'Checking this KPI with AI...'}
              </p>
              {aiError && <p className="exp-desk-kpi-trace-ai-error" role="alert">{aiError}</p>}
            </section>
          )}

          <section className="exp-desk-kpi-trace-section">
            <div className="exp-desk-kpi-trace-items-head">
              <h3 className="exp-desk-kpi-trace-section-title">
                Contributing records ({items.length})
              </h3>
            </div>

            {items.length === 0 ? (
              <p className="exp-desk-kpi-trace-empty">No records contributed to this KPI.</p>
            ) : purchasesDesk ? (
              <div className="exp-desk-kpi-trace-table-wrap">
                <table className="exp-desk-kpi-trace-table exp-desk-kpi-trace-table--purchases">
                  <thead>
                    <tr>
                      <th>{refLabel}</th>
                      <th>Payee</th>
                    </tr>
                  </thead>
                  <tbody>
                    {items.map((item) => (
                      <tr key={`${item.id ?? 0}-${item.expenseNumber}-${item.date}`}>
                        <td>
                          <div className="exp-desk-kpi-trace-exp-cell">
                            <span className="exp-desk-kpi-trace-exp-no">{cellText(item.expenseNumber)}</span>
                            {item.date && (
                              <span className="exp-desk-kpi-trace-exp-date">{formatDate(item.date)}</span>
                            )}
                          </div>
                        </td>
                        <td className="exp-desk-kpi-trace-payee">{cellText(item.payee)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="exp-desk-kpi-trace-table-wrap">
                <table className="exp-desk-kpi-trace-table">
                  <thead>
                    <tr>
                      <th>Expense / Date</th>
                      <th>Payee</th>
                      <th>Account</th>
                      <th>Payment</th>
                      <th>Amount</th>
                    </tr>
                  </thead>
                  <tbody>
                    {items.map((item) => (
                      <tr key={`${item.id ?? 0}-${item.expenseNumber}-${item.date}`}>
                        <td>
                          <div className="exp-desk-kpi-trace-exp-cell">
                            <span className="exp-desk-kpi-trace-exp-no">{cellText(item.expenseNumber)}</span>
                            {item.date && (
                              <span className="exp-desk-kpi-trace-exp-date">{formatDate(item.date)}</span>
                            )}
                          </div>
                        </td>
                        <td className="exp-desk-kpi-trace-payee">{cellText(item.payee)}</td>
                        <td>{cellText(item.account)}</td>
                        <td>{cellText(item.payment)}</td>
                        <td className="exp-desk-kpi-trace-amt">{formatCurrency(item.amount, item.currency)}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="exp-desk-kpi-trace-total-row">
                      <td colSpan={4} className="exp-desk-kpi-trace-total-label">Total</td>
                      <td className="exp-desk-kpi-trace-amt exp-desk-kpi-trace-total-amt">
                        {amountTotals.map(([currency, amount]) => (
                          <div key={currency}>{formatCurrency(amount, currency)}</div>
                        ))}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            )}

            {trace.footnote && (
              <p className="exp-desk-kpi-trace-footnote">{trace.footnote}</p>
            )}
          </section>
        </div>
      </div>
    </div>,
    document.body,
  );
}
