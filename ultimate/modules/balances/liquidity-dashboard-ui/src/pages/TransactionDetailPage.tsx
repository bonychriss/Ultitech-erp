import { useEffect, useState } from 'react';
import { ExternalLink, X } from 'lucide-react';
import { fetchTransactionDetail } from '../api';
import type { TransactionDetailPayload } from '../types';
import { navigateLdView } from '../nav';

export default function TransactionDetailPage({
  accountId,
  ym,
  txId,
  onClose,
}: {
  accountId: number;
  ym: string;
  txId: number;
  onClose?: () => void;
}) {
  const [data, setData] = useState<TransactionDetailPayload | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  function closeModal() {
    if (onClose) {
      onClose();
      return;
    }
    if (ym) {
      navigateLdView({ name: 'month', accountId, ym }, true);
      return;
    }
    if (accountId > 0) {
      navigateLdView({ name: 'account', accountId }, true);
      return;
    }
    navigateLdView({ name: 'dashboard' }, true);
  }

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError('');
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

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') closeModal();
    };
    window.addEventListener('keydown', onKey);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      window.removeEventListener('keydown', onKey);
      document.body.style.overflow = previousOverflow;
    };
  }, [accountId, ym, onClose]);

  const tx = data?.transaction;
  const title = tx ? `Transaction #${tx.id}` : `Transaction #${txId}`;

  return (
    <div className="ld-modal-backdrop" role="presentation" onClick={closeModal}>
      <div
        className="ld-modal ld-modal--tx"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ld-tx-modal-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="ld-modal-h">
          <div className="ld-tx-modal-heading">
            <h2 id="ld-tx-modal-title">{title}</h2>
            {tx ? (
              <p className="ld-tx-modal-sub">
                {tx.accountName}
                {tx.monthLabel ? ` · ${tx.monthLabel}` : ''}
              </p>
            ) : null}
          </div>
          <button type="button" className="ld-modal-x" aria-label="Close" onClick={closeModal}>
            <X className="w-4 h-4" aria-hidden="true" />
          </button>
        </div>

        <div className="ld-modal-b ld-tx-modal-b">
          {loading && <div className="ld-boot">Loading transaction...</div>}
          {!loading && (error || !tx) && (
            <div className="ld-boot-error" role="alert">
              <strong>Could not load transaction</strong>
              <p>{error || 'Unknown error'}</p>
            </div>
          )}
          {!loading && tx && (
            <>
              <div className="ld-tx-modal-hero">
                <div>
                  <div className="ld-tx-modal-hero-label">{tx.typeLabel}</div>
                  <div className={`ld-tx-modal-hero-value${tx.type === 'debit' ? ' is-neg' : ''}`}>
                    {tx.amountDisplay}
                  </div>
                </div>
                <span className={`ld-pill ld-pill--${tx.type}`}>{tx.typeLabel}</span>
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
                  <div className={`ld-summary-value ${tx.type === 'debit' ? 'is-out' : 'is-in'}`}>
                    {tx.amountDisplay}
                  </div>
                </div>
                <div className="ld-summary-card">
                  <div className="ld-summary-label">Balance After</div>
                  <div className="ld-summary-value">{tx.runningBalanceDisplay}</div>
                </div>
              </div>

              <h3>Transaction Details</h3>
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

              <h3>Why this amount is included</h3>
              <p className="ld-explain">{tx.whyIncluded}</p>

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
            </>
          )}
        </div>
      </div>
    </div>
  );
}
