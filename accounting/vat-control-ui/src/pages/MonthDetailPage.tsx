import { useEffect, useState } from 'react';
import { ArrowLeft, CheckCircle2, Lock, ChevronRight, AlertTriangle } from 'lucide-react';
import { fetchMonthDetail, postClosePeriod, postReconcilePeriod } from '../api';
import type { MonthDetailPayload } from '../types';
import { buildCrumbs, navigateVatView } from '../nav';
import Breadcrumbs from '../components/Breadcrumbs';

export default function MonthDetailPage({ ym }: { ym: string }) {
  const [data, setData] = useState<MonthDetailPayload | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState('');

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    (async () => {
      try {
        const payload = await fetchMonthDetail(ym);
        if (!cancelled) {
          setData(payload);
          setError('');
        }
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load month.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [ym]);

  async function onReconcile() {
    if (!window.confirm(`Mark ${ym} as reconciled?`)) return;
    setBusy('reconcile');
    try {
      const payload = await postReconcilePeriod(ym);
      setData(payload);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Reconcile failed.');
    } finally {
      setBusy('');
    }
  }

  async function onClose() {
    if (!window.confirm(`Close VAT period ${ym}? This locks the month opening / closing balances.`)) return;
    setBusy('close');
    try {
      const payload = await postClosePeriod(ym);
      setData(payload);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Close failed.');
    } finally {
      setBusy('');
    }
  }

  if (loading && !data) return <div className="ld-boot">Loading month detail...</div>;
  if ((error && !data) || !data) {
    return (
      <div className="ld-boot-error" role="alert">
        <strong>Could not load month</strong>
        <p>{error || 'Unknown error'}</p>
        <button
          type="button"
          className="ld-btn ld-btn--outline"
          onClick={() => navigateVatView({ name: 'dashboard' })}
        >
          Back to VAT Control
        </button>
      </div>
    );
  }

  const { summary, activity, reconciliation, period } = data;
  const crumbs = buildCrumbs({ name: 'month', ym }, { monthLabel: data.label });

  return (
    <div className="ld-page">
      <Breadcrumbs items={crumbs} />

      <div className="ld-header">
        <div>
          <button
            type="button"
            className="ld-back-link"
            onClick={() => navigateVatView({ name: 'dashboard' })}
          >
            <ArrowLeft className="w-4 h-4" aria-hidden="true" /> Back
          </button>
          <h1>{data.label}</h1>
          <p className="ld-header-sub">
            Status: {period.statusLabel}
            {period.closedAt ? ` - Closed ${period.closedAt}` : ''}
            {period.reconciledAt && period.status !== 'closed' ? ` - Reconciled ${period.reconciledAt}` : ''}
          </p>
        </div>
        <div className="ld-header-actions">
          {period.canReconcile && (
            <button
              type="button"
              className="ld-btn ld-btn--outline"
              disabled={!!busy}
              onClick={onReconcile}
            >
              <CheckCircle2 className="w-4 h-4" aria-hidden="true" />
              {busy === 'reconcile' ? 'Reconciling...' : 'Reconcile'}
            </button>
          )}
          {period.canClose && (
            <button
              type="button"
              className="ld-btn ld-btn--purple"
              disabled={!!busy}
              onClick={onClose}
            >
              <Lock className="w-4 h-4" aria-hidden="true" />
              {busy === 'close' ? 'Closing...' : 'Close period'}
            </button>
          )}
        </div>
      </div>

      {error && (
        <div className="ld-warn" role="status">
          <AlertTriangle className="w-4 h-4" aria-hidden="true" />
          {error}
        </div>
      )}

      <div className="ld-summary-grid">
        <div className="ld-summary-card">
          <div className="ld-summary-label">Opening VAT Balance</div>
          <div className="ld-summary-value">{summary.openingBalanceDisplay}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Output VAT</div>
          <div className="ld-summary-value">{summary.outputDisplay}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Input VAT - Purchases</div>
          <div className="ld-summary-value">{summary.inputPurchasesDisplay}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Input VAT - Expenses</div>
          <div className="ld-summary-value">{summary.inputExpensesDisplay}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Total Input VAT</div>
          <div className="ld-summary-value">{summary.inputTotalDisplay}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Net VAT</div>
          <div className={`ld-summary-value${summary.net < 0 ? ' is-neg' : ''}`}>{summary.netDisplay}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Closing VAT Balance</div>
          <div className="ld-summary-value">{summary.closingBalanceDisplay}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">VAT Position</div>
          <div className="ld-summary-value" style={{ fontSize: '1.1rem' }}>
            {summary.positionLabel}
          </div>
        </div>
      </div>

      {!data.hasActivity && (
        <div className="ld-empty" style={{ margin: '1rem 0', padding: '1.25rem' }}>
          No VAT activity for this period.
        </div>
      )}

      <section className="ld-card">
        <div className="ld-card-h">
          <h3>VAT Categories</h3>
        </div>
        <div className="ld-card-b ld-month-list">
          {activity.map((item) => (
            <button
              key={item.source}
              type="button"
              className="ld-month-row"
              onClick={() => navigateVatView({ name: 'transactions', ym, source: item.source })}
            >
              <div className="ld-month-row-main">
                <div className="ld-month-row-name">{item.label}</div>
                <div className="ld-month-row-meta">{item.description}</div>
              </div>
              <div className="ld-month-row-side">
                <div className="ld-month-row-close">{item.amountDisplay}</div>
                <div className="ld-month-row-close-label">
                  {item.source === 'output' ? 'Output VAT' : 'Input VAT'}
                </div>
                <ChevronRight className="w-4 h-4" aria-hidden="true" />
              </div>
            </button>
          ))}
        </div>
      </section>

      <section className="ld-card" style={{ marginTop: '1.25rem' }}>
        <div className="ld-card-h">
          <h3>VAT Reconciliation</h3>
          {reconciliation.isBalanced ? (
            <span className="ld-live">Reconciled</span>
          ) : (
            <span className="ld-warn" style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }}>
              <AlertTriangle className="w-4 h-4" aria-hidden="true" /> Difference found
            </span>
          )}
        </div>
        <div className="ld-card-b" style={{ overflowX: 'auto' }}>
          <table className="ld-table">
            <thead>
              <tr>
                <th>Source</th>
                <th>System VAT</th>
                <th>Module VAT</th>
                <th>Difference</th>
              </tr>
            </thead>
            <tbody>
              {[
                ['Revenue', 'outputDisplay'],
                ['Purchases', 'inputPurchasesDisplay'],
                ['Expenses', 'inputExpensesDisplay'],
                ['Input total', 'inputTotalDisplay'],
                ['Net VAT', 'netDisplay'],
              ].map(([label, key]) => (
                <tr key={key}>
                  <td>{label}</td>
                  <td>{String(reconciliation.system[key] ?? '-')}</td>
                  <td>{String(reconciliation.module[key] ?? '-')}</td>
                  <td>{String(reconciliation.difference[key] ?? '-')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {period.status === 'closed' && (
        <section className="ld-card" style={{ marginTop: '1.25rem' }}>
          <div className="ld-card-h">
            <h3>VAT Period Closed</h3>
            <span className="ld-live">Closed</span>
          </div>
          <div className="ld-card-b">
            <div className="ld-summary-grid">
              <div className="ld-summary-card">
                <div className="ld-summary-label">Period</div>
                <div className="ld-summary-value" style={{ fontSize: '1rem' }}>{data.label}</div>
              </div>
              <div className="ld-summary-card">
                <div className="ld-summary-label">Closed date</div>
                <div className="ld-summary-value" style={{ fontSize: '1rem' }}>{period.closedAt || '-'}</div>
              </div>
              <div className="ld-summary-card">
                <div className="ld-summary-label">Closing VAT balance</div>
                <div className="ld-summary-value" style={{ fontSize: '1rem' }}>{summary.closingBalanceDisplay}</div>
              </div>
              <div className="ld-summary-card">
                <div className="ld-summary-label">Position</div>
                <div className="ld-summary-value" style={{ fontSize: '1rem' }}>{summary.positionLabel}</div>
              </div>
            </div>
          </div>
        </section>
      )}
    </div>
  );
}
