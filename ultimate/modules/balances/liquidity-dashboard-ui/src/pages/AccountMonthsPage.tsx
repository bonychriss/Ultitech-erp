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

      <section className="ld-drill-section">
        <div className="ld-drill-section-h">
          <h3>Monthly Breakdown</h3>
        </div>
        <div className="ld-ledger-wrap">
          <table className="ld-ledger ld-ledger--months">
            <colgroup>
              <col className="ld-ledger-col-no" />
              <col />
              <col />
              <col />
              <col />
              <col />
              <col />
              <col className="ld-ledger-col-go" />
            </colgroup>
            <thead>
              <tr>
                <th className="ld-ledger-no">No.</th>
                <th>Month</th>
                <th className="ld-ledger-num">Transactions</th>
                <th className="ld-ledger-num">Opening</th>
                <th className="ld-ledger-num">Money In</th>
                <th className="ld-ledger-num">Money Out</th>
                <th className="ld-ledger-num">Closing</th>
                <th aria-label="Open" />
              </tr>
            </thead>
            <tbody>
              {months.map((month, index) => {
                const open = () => navigateLdView({ name: 'month', accountId, ym: month.ym });
                return (
                  <tr
                    key={month.ym}
                    tabIndex={0}
                    onClick={open}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        open();
                      }
                    }}
                  >
                    <td className="ld-ledger-no">{index + 1}</td>
                    <td>
                      <span className="ld-ledger-name">{month.label}</span>
                    </td>
                    <td className="ld-ledger-num ld-ledger-muted">{month.transactionCount}</td>
                    <td className="ld-ledger-num ld-ledger-muted">{month.openingBalanceDisplay}</td>
                    <td className="ld-ledger-num ld-ledger-in">{month.moneyInDisplay}</td>
                    <td className="ld-ledger-num ld-ledger-out">{month.moneyOutDisplay}</td>
                    <td className={`ld-ledger-num ld-ledger-bal${month.closingBalance < 0 ? ' is-neg' : ''}`}>
                      {month.closingBalanceDisplay}
                    </td>
                    <td className="ld-ledger-go">
                      <ChevronRight className="w-4 h-4" aria-hidden="true" />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
