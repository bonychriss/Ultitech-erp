import { useEffect, useState } from 'react';
import { ArrowLeft, ExternalLink, Search } from 'lucide-react';
import { fetchMonthTransactions } from '../api';
import type { MonthTransactionsPayload } from '../types';
import { buildCrumbs, navigateLdView } from '../nav';
import Breadcrumbs from '../components/Breadcrumbs';

export default function MonthTransactionsPage({
  accountId,
  ym,
}: {
  accountId: number;
  ym: string;
}) {
  const [data, setData] = useState<MonthTransactionsPayload | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [type, setType] = useState('');
  const [q, setQ] = useState('');
  const [qDraft, setQDraft] = useState('');

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    (async () => {
      try {
        const payload = await fetchMonthTransactions(accountId, ym, { type, q });
        if (!cancelled) {
          setData(payload);
          setError('');
        }
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load month transactions.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [accountId, ym, type, q]);

  if (loading && !data) return <div className="ld-boot">Loading month details...</div>;
  if ((error && !data) || !data) {
    return (
      <div className="ld-boot-error" role="alert">
        <strong>Could not load month</strong>
        <p>{error || 'Unknown error'}</p>
        <button
          type="button"
          className="ld-btn ld-btn--outline"
          onClick={() => navigateLdView({ name: 'account', accountId })}
        >
          Back to account
        </button>
      </div>
    );
  }

  const { account, month, transactions } = data;
  const crumbs = buildCrumbs(
    { name: 'month', accountId, ym },
    { accountName: account.name, monthLabel: month.label },
  );

  return (
    <div className="ld-page">
      <Breadcrumbs items={crumbs} />

      <div className="ld-header">
        <div>
          <button
            type="button"
            className="ld-back-link"
            onClick={() => navigateLdView({ name: 'account', accountId })}
          >
            <ArrowLeft className="w-4 h-4" aria-hidden="true" /> Back
          </button>
          <h1>
            {account.name}
            <span className="ld-header-muted"> - {month.label}</span>
          </h1>
          <p className="ld-header-sub">Opening + Money In - Money Out = Closing</p>
        </div>
      </div>

      <div className="ld-summary-grid ld-summary-grid--4">
        <div className="ld-summary-card">
          <div className="ld-summary-label">Opening Balance</div>
          <div className="ld-summary-value">{month.openingBalanceDisplay}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Money In</div>
          <div className="ld-summary-value is-in">{month.moneyInDisplay}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Money Out</div>
          <div className="ld-summary-value is-out">{month.moneyOutDisplay}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Closing Balance</div>
          <div className="ld-summary-value">{month.closingBalanceDisplay}</div>
        </div>
      </div>

      <section className="ld-card">
        <div className="ld-card-h ld-card-h--filters">
          <h3>Transactions ({transactions.length})</h3>
          <div className="ld-filters">
            <select
              className="ld-input"
              value={type}
              onChange={(e) => setType(e.target.value)}
              aria-label="Transaction type"
            >
              <option value="">All types</option>
              <option value="credit">Money In (Credit)</option>
              <option value="debit">Money Out (Debit)</option>
            </select>
            <form
              className="ld-search"
              onSubmit={(e) => {
                e.preventDefault();
                setQ(qDraft.trim());
              }}
            >
              <Search className="w-4 h-4" aria-hidden="true" />
              <input
                className="ld-input"
                value={qDraft}
                onChange={(e) => setQDraft(e.target.value)}
                placeholder="Search description / reference"
              />
            </form>
          </div>
        </div>
        <div className="ld-card-b ld-table-wrap">
          {loading && <div className="ld-inline-loading">Refreshing...</div>}
          {transactions.length === 0 ? (
            <div className="ld-empty">No transactions for this month with the current filters.</div>
          ) : (
            <table className="ld-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Ref / Tx No.</th>
                  <th>Description</th>
                  <th>Type</th>
                  <th className="is-num">Debit</th>
                  <th className="is-num">Credit</th>
                  <th className="is-num">Running Balance</th>
                  <th>Source</th>
                  <th>Created By</th>
                </tr>
              </thead>
              <tbody>
                {transactions.map((tx) => (
                  <tr
                    key={tx.id}
                    className="is-clickable"
                    onClick={() =>
                      navigateLdView({
                        name: 'transaction',
                        accountId,
                        ym,
                        txId: tx.id,
                      })
                    }
                  >
                    <td>{tx.transactionDate}</td>
                    <td>#{tx.id}</td>
                    <td>{tx.description || '-'}</td>
                    <td>
                      <span className={`ld-pill ld-pill--${tx.type}`}>{tx.typeLabel}</span>
                    </td>
                    <td className="is-num is-out">{tx.debitDisplay}</td>
                    <td className="is-num is-in">{tx.creditDisplay}</td>
                    <td className="is-num">{tx.runningBalanceDisplay}</td>
                    <td>
                      {tx.sourceUrl ? (
                        <a
                          href={tx.sourceUrl}
                          className="ld-source-link"
                          onClick={(e) => e.stopPropagation()}
                          target="_blank"
                          rel="noreferrer"
                        >
                          {tx.referenceLabel || 'Open'} <ExternalLink className="w-3 h-3" aria-hidden="true" />
                        </a>
                      ) : (
                        tx.referenceLabel || '-'
                      )}
                    </td>
                    <td>{tx.createdBy}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </section>
    </div>
  );
}
