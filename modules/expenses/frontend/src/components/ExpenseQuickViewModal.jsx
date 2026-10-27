import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { CheckCircle2, ExternalLink, Pencil, Trash2, X } from 'lucide-react';
import ExpenseStatusBadge, { canDeleteDraftExpense, isVoucherExpense } from './ExpenseStatusBadge';
import { formatDateDigits, formatVoucherRef } from '../utils/formatDisplay';

function formatCurrency(value, currencyCode) {
  const code = String(currencyCode || 'TZS').replace(/^TSh$/i, 'TZS');
  const amount = Number(value) || 0;
  return `${code} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(dateStr) {
  return formatDateDigits(dateStr);
}

function detailRow(label, value, { amount = false } = {}) {
  if (value === null || value === undefined || value === '' || value === '-') return null;
  return (
    <div className="exp-desk-quick-row">
      <dt>{label}</dt>
      <dd className={amount ? 'exp-desk-amt' : undefined}>{value}</dd>
    </div>
  );
}

export default function ExpenseQuickViewModal({
  expense,
  onClose,
  onDeleteDraft,
  onPostDraft,
  onEditDraft,
  deleting = false,
  posting = false,
}) {
  const taxAmount = Number(expense.tax_amount) || 0;
  const accountMain = expense.main_account_name && expense.main_account_name !== '-'
    ? expense.main_account_name
    : '';
  const accountSub = expense.sub_account_name && expense.sub_account_name !== '-'
    ? expense.sub_account_name
    : '';
  const accountLabel = accountSub && accountSub !== accountMain
    ? `${accountMain} / ${accountSub}`
    : (expense.category_name || accountMain || '');
  const draft = canDeleteDraftExpense(expense);
  const voucher = isVoucherExpense(expense);
  const voucherUrl = expense.voucher_url
    || (expense.pv_id ? `../../employee/view-voucher.php?id=${expense.pv_id}&module=voucher` : '');
  const entryLabel = formatVoucherRef(
    expense.voucher_entry || expense.expense_number,
    expense.expense_number || `Expense #${expense.id}`,
  );
  const officialRef = String(expense.voucher_no_official || '').trim();
  const officialRefLabel = officialRef
    ? formatVoucherRef(officialRef, officialRef)
    : '';
  const showOfficialRef = voucher && officialRefLabel
    && officialRefLabel !== entryLabel;
  const busy = deleting || posting;

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function handleKeyDown(event) {
      if (event.key === 'Escape' && !busy) onClose();
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [onClose, busy]);

  return createPortal(
    <div className="exp-desk-modal-backdrop" onClick={busy ? undefined : onClose} role="presentation">
      <div
        className="exp-desk-modal exp-desk-quick-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="exp-desk-quick-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="exp-desk-quick-head">
          <div className="exp-desk-quick-head-main">
            <h2 id="exp-desk-quick-title" className="exp-desk-quick-title">
              {entryLabel}
            </h2>
            <p className="exp-desk-quick-subtitle">{expense.display_name || expense.payee || expense.description || '-'}</p>
            <div className="exp-desk-quick-meta">
              <ExpenseStatusBadge expense={expense} />
              {voucher ? <span className="exp-desk-quick-source">Payment voucher</span> : null}
              <span className="exp-desk-quick-date">{formatDate(expense.date)}</span>
            </div>
          </div>
          <button
            type="button"
            className="exp-desk-quick-close"
            onClick={onClose}
            aria-label="Close"
            disabled={busy}
          >
            <X className="w-5 h-5" aria-hidden="true" />
          </button>
        </div>

        <div className="exp-desk-quick-body">
          <dl className="exp-desk-quick-details">
            {detailRow('Amount', formatCurrency(expense.amount, expense.currency_code), { amount: true })}
            {showOfficialRef && detailRow(
              'Reference',
              voucherUrl ? (
                <a className="exp-desk-quick-ref-link" href={voucherUrl}>
                  {officialRefLabel}
                </a>
              ) : officialRefLabel,
            )}
            {detailRow(voucher ? 'Budget / account' : 'Account', accountLabel)}
            {detailRow(voucher ? 'Payment type' : 'Paid via', expense.payment_method_label)}
            {!voucher && detailRow('Bank / cash', expense.source_account_name)}
            {voucher && detailRow('Prepared by', expense.prepared_by)}
            {taxAmount > 0 && detailRow('Tax', formatCurrency(taxAmount, expense.currency_code))}
            {detailRow('Description', expense.description)}
          </dl>
        </div>

        {voucher && voucherUrl ? (
          <div className="exp-desk-quick-actions">
            <a
              className="exp-desk-btn exp-desk-btn-primary exp-desk-quick-action-btn"
              href={voucherUrl}
            >
              <ExternalLink size={16} aria-hidden="true" />
              Open voucher
            </a>
          </div>
        ) : null}

        {draft && (
          <div className="exp-desk-quick-actions">
            <button
              type="button"
              className="exp-desk-btn exp-desk-btn-primary exp-desk-quick-action-btn"
              onClick={() => onPostDraft?.(expense)}
              disabled={busy}
            >
              <CheckCircle2 size={16} aria-hidden="true" />
              {posting ? 'Posting…' : 'Post expense'}
            </button>
            <button
              type="button"
              className="exp-desk-btn exp-desk-btn-secondary exp-desk-quick-action-btn"
              onClick={() => onEditDraft?.(expense)}
              disabled={busy}
            >
              <Pencil size={16} aria-hidden="true" />
              Edit draft
            </button>
            <button
              type="button"
              className="exp-desk-btn exp-desk-btn-secondary exp-desk-quick-action-btn exp-desk-quick-action-btn--danger"
              onClick={() => onDeleteDraft?.(expense)}
              disabled={busy}
            >
              <Trash2 size={16} aria-hidden="true" />
              {deleting ? 'Deleting...' : 'Delete draft'}
            </button>
          </div>
        )}
      </div>
    </div>,
    document.body,
  );
}
