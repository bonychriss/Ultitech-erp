import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { ChevronRight, AlertTriangle, PlayCircle, BookCheck, Wallet } from 'lucide-react';
import { fetchAccountMonths } from '../api';
import type { AccountMonthsPayload } from '../types';
import { buildCrumbs, navigateLdView } from '../nav';
import Breadcrumbs from '../components/Breadcrumbs';

export default function AccountMonthsPage({ accountId }: { accountId: number }) {
  const [data, setData] = useState<AccountMonthsPayload | null>(null);
  const [topBar, setTopBar] = useState<Element | null>(null);
  useEffect(() => { setTopBar(document.querySelector('header .header-content')); }, []);
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
      <div className="ld-header ld-header--inline">
        <div>
          <h1 className="ld-title-desktop">{account.name}</h1>
          {topBar && createPortal(<span className="ld-topbar-title">{account.name}</span>, topBar)}
          <Breadcrumbs items={crumbs} />
        </div>
      </div>

      <div className="ld-summary-grid">
        <div className="ld-summary-card ld-summary-card--icon">
          <span className="ld-summary-icon ld-summary-icon--open"><PlayCircle className="w-4 h-4" aria-hidden="true" /></span>
          <div>
            <div className="ld-summary-label">Opening Balance</div>
            <div className="ld-summary-value">{account.openingBalanceDisplay}</div>
          </div>
        </div>
        <div className="ld-summary-card ld-summary-card--icon">
          <span className="ld-summary-icon ld-summary-icon--close"><BookCheck className="w-4 h-4" aria-hidden="true" /></span>
          <div>
            <div className="ld-summary-label">Ledger Closing</div>
            <div className="ld-summary-value">{account.reconcilingCloseDisplay}</div>
          </div>
        </div>
        <div className="ld-summary-card ld-summary-card--icon">
          <span className="ld-summary-icon ld-summary-icon--live"><Wallet className="w-4 h-4" aria-hidden="true" /></span>
          <div>
            <div className="ld-summary-label">Live Balance</div>
            <div className="ld-summary-value">{account.balanceDisplay}</div>
          </div>
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
        <ul className="ld-acc-cards ld-month-cards">
          {months.map((month, index) => (
            <li key={month.ym}>
              <button
                type="button"
                className="ld-acc-card ld-month-card"
                onClick={() => navigateLdView({ name: 'month', accountId, ym: month.ym })}
              >
                <span className={`ld-acc-card-no ld-acc-card-no--${index % 6}`}>{index + 1}</span>
                <span className="ld-acc-card-main">
                  <span className="ld-acc-card-name">{month.label}</span>
                  <span className="ld-acc-card-meta">
                    {month.transactionCount} transaction{month.transactionCount === 1 ? '' : 's'}
                  </span>
                </span>
                <span className={`ld-acc-card-bal${month.closingBalance < 0 ? ' is-neg' : ''}`}>
                  {month.closingBalanceDisplay}
                </span>
                <ChevronRight className="w-4 h-4 ld-acc-card-go" aria-hidden="true" />
                <span className="ld-month-card-flow">
                  <span className="ld-month-card-in">In {month.moneyInDisplay}</span>
                  <span className="ld-month-card-out">Out {month.moneyOutDisplay}</span>
                </span>
              </button>
            </li>
          ))}
        </ul>
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
