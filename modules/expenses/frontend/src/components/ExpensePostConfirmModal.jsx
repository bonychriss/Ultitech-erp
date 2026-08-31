import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { ArrowRight, CheckCircle2, Loader2, X } from 'lucide-react';

function formatMoney(value, currencyCode) {
  const code = String(currencyCode || 'TZS').replace(/^TSh$/i, 'TZS');
  const amount = Number(value) || 0;
  return `${code} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export default function ExpensePostConfirmModal({
  expense,
  preview,
  loading = false,
  posting = false,
  posted = false,
  error = '',
  onConfirm,
  onClose,
}) {
  const [revealAfter, setRevealAfter] = useState(false);
  const label = expense?.expense_number || (expense?.id ? `Expense #${expense.id}` : 'Expense');
  const source = preview?.source_account || posted?.source_account || null;
  const currency = preview?.currency_code || posted?.currency_code || expense?.currency_code || 'TZS';
  const amount = preview?.amount ?? posted?.amount ?? expense?.amount ?? 0;
  const accountName = source?.name || expense?.source_account_name || 'Bank / cash account';

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function handleKeyDown(event) {
      if (event.key === 'Escape' && !posting) onClose();
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [onClose, posting]);

  useEffect(() => {
    setRevealAfter(false);
    if (!preview || loading) return undefined;
    const timer = window.setTimeout(() => setRevealAfter(true), 280);
    return () => window.clearTimeout(timer);
  }, [preview, loading, expense?.id]);

  useEffect(() => {
    if (!posted) return undefined;
    setRevealAfter(true);
    const timer = window.setTimeout(() => onClose?.(), 1800);
    return () => window.clearTimeout(timer);
  }, [posted, onClose]);

  const before = source?.balance_before;
  const after = source?.balance_after;

  return createPortal(
    <div
      className="exp-desk-modal-backdrop exp-post-confirm-backdrop"
      onClick={posting || posted ? undefined : onClose}
      role="presentation"
    >
      <div
        className={`exp-desk-modal exp-post-confirm-modal${posted ? ' is-posted' : ''}${revealAfter ? ' is-revealed' : ''}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby="exp-post-confirm-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="exp-post-confirm-head">
          <div>
            <p className="exp-post-confirm-kicker">{posted ? 'Posted' : 'Confirm posting'}</p>
            <h2 id="exp-post-confirm-title" className="exp-post-confirm-title">
              {posted ? `${label} is on the ledger` : `Post ${label} to the ledger?`}
            </h2>
            <p className="exp-post-confirm-sub">
              {posted
                ? 'Bank and cash balances have been updated.'
                : 'Bank and cash balances will update when you post.'}
            </p>
          </div>
          {!posting && !posted ? (
            <button type="button" className="exp-desk-quick-close" onClick={onClose} aria-label="Close">
              <X className="w-5 h-5" aria-hidden="true" />
            </button>
          ) : null}
        </div>

        <div className="exp-post-confirm-body">
          {loading ? (
            <div className="exp-post-confirm-loading" role="status">
              <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
              <span>Loading account balances�</span>
            </div>
          ) : null}

          {error ? (
            <div className="exp-desk-flash exp-desk-flash-error" role="alert">
              {error}
            </div>
          ) : null}

          {!loading && !error && source ? (
            <>
              <div className="exp-post-confirm-amount">
                <span>Amount to post</span>
                <strong>{formatMoney(amount, currency)}</strong>
              </div>

              <div className="exp-post-confirm-account">
                <span className="exp-post-confirm-account-label">Paid from</span>
                <strong>{accountName}</strong>
              </div>

              <div className="exp-post-balance-stage" aria-live="polite">
                <div className="exp-post-balance-card exp-post-balance-card--before">
                  <span className="exp-post-balance-label">Before</span>
                  <strong className="exp-post-balance-value">{formatMoney(before, currency)}</strong>
                </div>

                <div className="exp-post-balance-arrow" aria-hidden="true">
                  <span className="exp-post-balance-arrow-line" />
                  <ArrowRight size={18} />
                </div>

                <div className="exp-post-balance-card exp-post-balance-card--after">
                  <span className="exp-post-balance-label">{posted ? 'After (posted)' : 'After'}</span>
                  <strong className="exp-post-balance-value">{formatMoney(after, currency)}</strong>
                  <span className="exp-post-balance-delta">
                    ?{formatMoney(amount, currency)}
                  </span>
                </div>
              </div>

              {posted ? (
                <div className="exp-post-confirm-success" role="status">
                  <CheckCircle2 size={20} aria-hidden="true" />
                  <span>Balances updated successfully</span>
                </div>
              ) : null}
            </>
          ) : null}
        </div>

        {!posted ? (
          <div className="exp-post-confirm-actions">
            <button
              type="button"
              className="exp-desk-btn exp-desk-btn-secondary"
              onClick={onClose}
              disabled={posting || loading}
            >
              Cancel
            </button>
            <button
              type="button"
              className="exp-desk-btn exp-desk-btn-primary"
              onClick={onConfirm}
              disabled={posting || loading || !!error || !preview}
            >
              {posting ? (
                <>
                  <Loader2 className="exp-imp-spin" size={16} aria-hidden="true" />
                  Posting�
                </>
              ) : (
                <>
                  <CheckCircle2 size={16} aria-hidden="true" />
                  Post expense
                </>
              )}
            </button>
          </div>
        ) : null}
      </div>
    </div>,
    document.body,
  );
}
