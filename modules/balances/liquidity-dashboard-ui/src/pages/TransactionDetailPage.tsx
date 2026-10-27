import { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import { HiOutlineArrowDownLeft, HiOutlineArrowUpRight } from 'react-icons/hi2';
import { fetchTransactionDetail } from '../api';
import type { TransactionDetailPayload } from '../types';
import { navigateLdView } from '../nav';

function formatTxDate(raw: string): string {
  if (!raw) return '—';
  const d = new Date(raw.includes('T') || raw.includes(' ') ? raw : `${raw}T12:00:00`);
  if (Number.isNaN(d.getTime())) return raw;
  const day = d.toLocaleString('en-GB', { day: '2-digit' });
  const mon = d.toLocaleString('en-GB', { month: 'short' });
  const year = d.getFullYear();
  const time = d.toLocaleTimeString('en-GB', { hour12: false });
  return `${day} ${mon} ${year}, ${time}`;
}

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
  const isOut = tx?.type === 'debit';
  const flowLabel = isOut ? 'Money Out' : 'Money In';
  return (
    <div className="ld-modal-backdrop" role="presentation" onClick={closeModal}>
      <div
        className="ld-modal ld-modal--tx"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ld-tx-modal-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="ld-tx-modal-top">
          <h2 id="ld-tx-modal-title">{title}</h2>
          <button type="button" className="ld-modal-x" aria-label="Close" onClick={closeModal}>
            <X className="w-4 h-4" aria-hidden="true" />
          </button>
        </div>

        <div className="ld-tx-modal-body">
          {loading && <div className="ld-boot">Loading transaction...</div>}
          {!loading && (error || !tx) && (
            <div className="ld-boot-error" role="alert">
              <strong>Could not load transaction</strong>
              <p>{error || 'Unknown error'}</p>
            </div>
          )}
          {!loading && tx && (
            <>
              <div className="ld-tx-modal-flow">
                <span className={`ld-tx-modal-flow-ico${isOut ? ' is-out' : ' is-in'}`} aria-hidden="true">
                  {isOut ? (
                    <HiOutlineArrowUpRight size={22} />
                  ) : (
                    <HiOutlineArrowDownLeft size={22} />
                  )}
                </span>
                <div className="ld-tx-modal-flow-copy">
                  <div className="ld-tx-modal-flow-label">{flowLabel}</div>
                  <div className={`ld-tx-modal-flow-amount${isOut ? ' is-out' : ' is-in'}`}>
                    {tx.amountDisplay}
                  </div>
                </div>
              </div>

              <dl className="ld-tx-modal-kv">
                <div className="ld-tx-modal-row">
                  <dt>Date</dt>
                  <dd>{formatTxDate(tx.transactionDate)}</dd>
                </div>
                <div className="ld-tx-modal-row">
                  <dt>Account</dt>
                  <dd>{tx.accountName || '—'}</dd>
                </div>
                <div className="ld-tx-modal-row">
                  <dt>Description</dt>
                  <dd>{tx.description || '—'}</dd>
                </div>
                <div className="ld-tx-modal-row">
                  <dt>Month</dt>
                  <dd>{tx.monthLabel || '—'}</dd>
                </div>
                <div className="ld-tx-modal-row">
                  <dt>Balance After</dt>
                  <dd>{tx.runningBalanceDisplay || '—'}</dd>
                </div>
              </dl>
            </>
          )}
        </div>

        <div className="ld-tx-modal-foot">
          <button type="button" className="ld-btn ld-btn--outline" onClick={closeModal}>
            Close
          </button>
        </div>
      </div>
    </div>
  );
}
