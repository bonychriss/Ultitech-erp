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
          <p className="ld-header-sub">{tx.accountName}</p>
        </div>
        <div className="ld-header-hero">
          <div className="ld-header-hero-label">{tx.typeLabel}</div>
          <div className={`ld-header-hero-value${tx.type === 'debit' ? ' is-neg' : ''}`}>
            {tx.amountDisplay}
          </div>
        </div>
      </div>

      <section className="ld-card">
        <div className="ld-card-h">
          <h3>Why this amount is included</h3>
        </div>
        <div className="ld-card-b">
          <p className="ld-explain">{tx.whyIncluded}</p>
          <dl className="ld-detail-grid">
            <div>
              <dt>Date</dt>
              <dd>{tx.transactionDate || '-'}</dd>
            </div>
            <div>
              <dt>Description</dt>
              <dd>{tx.description || '-'}</dd>
            </div>
            <div>
              <dt>Account</dt>
              <dd>{tx.accountName}</dd>
            </div>
            <div>
              <dt>Running balance after this entry</dt>
              <dd>{tx.runningBalanceDisplay}</dd>
            </div>
            <div>
              <dt>Created by</dt>
              <dd>{tx.createdBy}</dd>
            </div>
            <div>
              <dt>Reference</dt>
              <dd>{tx.referenceLabel || '-'}</dd>
            </div>
          </dl>

          <div className="ld-detail-actions">
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
      </section>
    </div>
  );
}
