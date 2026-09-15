import { useEffect, useState } from 'react';
import { ArrowLeft, ChevronRight, AlertTriangle } from 'lucide-react';
import { fetchAccountMonths } from '../api';
import type { AccountMonthsPayload } from '../types';
import { buildCrumbs, navigateLdView } from '../nav';
import Breadcrumbs from '../components/Breadcrumbs';

export default function AccountMonthsPage({ accountId }: { accountId: number }) {
  const [data, setData] = useState<AccountMonthsPayload | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const payload = await fetchAccountMonths(accountId);
        if (!cancelled) setData(payload);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load account months.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [accountId]);

  if (loading) return <div className="ld-boot">Loading account history...</div>;
  if (error || !data) {
    return (
      <div className="ld-boot-error" role="alert">
        <strong>Could not load account</strong>
        <p>{error || 'Unknown error'}</p>
        <button type="button" className="ld-btn ld-btn--outline" onClick={() => navigateLdView({ name: 'liquidity' })}>
          Back to Total Liquidity
        </button>
      </div>
    );
  }

  const { account, months } = data;
  const crumbs = buildCrumbs(
    { name: 'account', accountId },
    { accountName: account.name },
  );

  return (
    <div className="ld-page">
      <Breadcrumbs items={crumbs} />

      <div className="ld-header">
        <div>
          <button type="button" className="ld-back-link" onClick={() => navigateLdView({ name: 'liquidity' })}>
            <ArrowLeft className="w-4 h-4" aria-hidden="true" /> Back
          </button>
          <h1>{account.name}</h1>
          <p className="ld-header-sub">
            {account.typeLabel} - {account.bucketLabel}
            {account.code ? ` - ${account.code}` : ''}
          </p>
        </div>
        <div className="ld-header-hero">
          <div className="ld-header-hero-label">Current Balance</div>
          <div className={`ld-header-hero-value${account.balance < 0 ? ' is-neg' : ''}`}>
            {account.balanceDisplay}
          </div>
        </div>
      </div>

      <div className="ld-summary-grid">
        <div className="ld-summary-card">
          <div className="ld-summary-label">Opening Balance</div>
          <div className="ld-summary-value">{account.openingBalanceDisplay}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Ledger Closing</div>
          <div className="ld-summary-value">{account.reconcilingCloseDisplay}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Live Balance</div>
          <div className="ld-summary-value">{account.balanceDisplay}</div>
        </div>
      </div>

      {!account.balanceMatchesLedger && (
        <div className="ld-warn" role="status">
          <AlertTriangle className="w-4 h-4" aria-hidden="true" />
          Live balance and month-chain closing differ. Review monthly money-in/out for gaps or date issues.
        </div>
      )}

      <section className="ld-card">
        <div className="ld-card-h">
          <h3>Monthly Breakdown</h3>
        </div>
        <div className="ld-card-b ld-month-list">
          {months.map((month) => (
            <button
              key={month.ym}
              type="button"
              className="ld-month-row"
              onClick={() => navigateLdView({ name: 'month', accountId, ym: month.ym })}
            >
              <div className="ld-month-row-main">
                <div className="ld-month-row-name">{month.label}</div>
                <div className="ld-month-row-meta">
                  {month.transactionCount} transaction{month.transactionCount === 1 ? '' : 's'}
                </div>
                <div className="ld-month-row-flow">
                  <span>Open {month.openingBalanceDisplay}</span>
                  <span className="is-in">In {month.moneyInDisplay}</span>
                  <span className="is-out">Out {month.moneyOutDisplay}</span>
                </div>
              </div>
              <div className="ld-month-row-side">
                <div className="ld-month-row-close">{month.closingBalanceDisplay}</div>
                <div className="ld-month-row-close-label">Closing</div>
                <ChevronRight className="w-4 h-4" aria-hidden="true" />
              </div>
            </button>
          ))}
        </div>
      </section>
    </div>
  );
}
