import { useEffect, useState } from 'react';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { fetchTransactionDetail } from '../api';
import type { TransactionDetailPayload } from '../types';
import { buildCrumbs, navigateLdView } from '../nav';
import Breadcrumbs from '../components/Breadcrumbs';

export default function TransactionDetailPage({
  accountId,
  ym,
  txId,
}: {
  accountId: number;
  ym: string;
  txId: number;
}) {
  const [data, setData] = useState<TransactionDetailPayload | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const payload = await fetchTransactionDetail(txId);
        if (!cancelled) setData(payload);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load transaction.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [txId]);

  if (loading) return <div className="ld-boot">Loading transaction...</div>;
  if (error || !data) {
    return (
      <div className="ld-boot-error" role="alert">
        <strong>Could not load transaction</strong>
        <p>{error || 'Unknown error'}</p>
        <button
          type="button"
          className="ld-btn ld-btn--outline"
          onClick={() =>
            ym
              ? navigateLdView({ name: 'month', accountId, ym })
              : navigateLdView({ name: 'account', accountId })
          }
        >
          Back
        </button>
      </div>
    );
  }

  const tx = data.transaction;
  const backYm = ym || tx.ym;
  const crumbs = buildCrumbs(
    { name: 'transaction', accountId: tx.accountId || accountId, ym: backYm, txId },
    {
      accountName: tx.accountName,
      monthLabel: tx.monthLabel,
      txLabel: `Transaction #${tx.id}`,
    },
  );

  return (
    <div className="ld-page">
      <Breadcrumbs items={crumbs} />

      <div className="ld-header">
        <div>
          <button
            type="button"
            className="ld-back-link"
            onClick={() =>
              navigateLdView({
                name: 'month',
                accountId: tx.accountId || accountId,
                ym: backYm,
              })
            }
          >
            <ArrowLeft className="w-4 h-4" aria-hidden="true" /> Back
          </button>
          <h1>Transaction #{tx.id}</h1>
          <p className="ld-header-sub">
            {tx.accountName}
            {tx.monthLabel ? ` - ${tx.monthLabel}` : ''}
          </p>
        </div>
        <div className="ld-header-hero">
          <div className="ld-header-hero-label">{tx.typeLabel}</div>
          <div className={`ld-header-hero-value${tx.type === 'debit' ? ' is-neg' : ''}`}>
            {tx.amountDisplay}
          </div>
        </div>
      </div>

      <div className="ld-summary-grid ld-summary-grid--4">
        <div className="ld-summary-card">
          <div className="ld-summary-label">Date</div>
          <div className="ld-summary-value">{tx.transactionDate || '-'}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Type</div>
          <div className="ld-summary-value">
            <span className={`ld-pill ld-pill--${tx.type}`}>{tx.typeLabel}</span>
          </div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Amount</div>
          <div className={`ld-summary-value ${tx.type === 'debit' ? 'is-out' : 'is-in'}`}>{tx.amountDisplay}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Balance After</div>
          <div className="ld-summary-value">{tx.runningBalanceDisplay}</div>
        </div>
      </div>

      <section className="ld-drill-section">
        <div className="ld-drill-section-h">
          <h3>Transaction Details</h3>
        </div>
        <div className="ld-ledger-wrap">
          <table className="ld-ledger ld-ledger--static ld-ledger--kv">
            <colgroup>
              <col className="ld-ledger-col-label" />
              <col />
            </colgroup>
            <thead>
              <tr>
                <th>Field</th>
                <th>Detail</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td className="ld-ledger-label">Transaction No.</td>
                <td>
                  <span className="ld-ledger-name">#{tx.id}</span>
                </td>
              </tr>
              <tr>
                <td className="ld-ledger-label">Account</td>
                <td>{tx.accountName}</td>
              </tr>
              <tr>
                <td className="ld-ledger-label">Month</td>
                <td>{tx.monthLabel || '-'}</td>
              </tr>
              <tr>
                <td className="ld-ledger-label">Description</td>
                <td>{tx.description || '-'}</td>
              </tr>
              <tr>
                <td className="ld-ledger-label">Reference</td>
                <td>
                  {tx.sourceUrl ? (
                    <a href={tx.sourceUrl} className="ld-source-link" target="_blank" rel="noreferrer">
                      {tx.referenceLabel || 'Open'} <ExternalLink className="w-3 h-3" aria-hidden="true" />
                    </a>
                  ) : (
                    tx.referenceLabel || '-'
                  )}
                </td>
              </tr>
              <tr>
                <td className="ld-ledger-label">Created By</td>
                <td>{tx.createdBy || '-'}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section className="ld-card">
        <div className="ld-card-h">
          <h3>Why this amount is included</h3>
        </div>
        <div className="ld-card-b">
          <p className="ld-explain">{tx.whyIncluded}</p>
        </div>
      </section>

      <div className="ld-detail-actions ld-detail-actions--page">
        {tx.sourceUrl && (
          <a className="ld-btn ld-btn--purple" href={tx.sourceUrl} target="_blank" rel="noreferrer">
            Open source document <ExternalLink className="w-4 h-4" aria-hidden="true" />
          </a>
        )}
        <a className="ld-btn ld-btn--outline" href={tx.viewUrl}>
          Open classic transaction view
        </a>
      </div>
    </div>
  );
}
