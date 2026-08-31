import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { CheckCircle2, Pencil, Trash2, X } from 'lucide-react';
import ExpenseStatusBadge, { canDeleteDraftExpense } from './ExpenseStatusBadge';
import { deskPageUrl } from '../api/expensesDesk';

function formatCurrency(value, currencyCode) {
  const code = String(currencyCode || 'TZS').replace(/^TSh$/i, 'TZS');
  const amount = Number(value) || 0;
  return `${code} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(dateStr) {
  if (!dateStr) return '-';
  const normalized = dateStr.includes('T') ? dateStr : `${dateStr}T12:00:00`;
  return new Date(normalized).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
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
              {expense.expense_number || `Expense #${expense.id}`}
            </h2>
            <p className="exp-desk-quick-subtitle">{expense.display_name || expense.payee || expense.description || '-'}</p>
            <div className="exp-desk-quick-meta">
              <ExpenseStatusBadge expense={expense} />
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
            {detailRow('Account', accountLabel)}
            {detailRow('Paid via', expense.payment_method_label)}
            {detailRow('Bank / cash', expense.source_account_name)}
            {taxAmount > 0 && detailRow('Tax', formatCurrency(taxAmount, expense.currency_code))}
            {detailRow('Description', expense.description)}
          </dl>
        </div>

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
            <a
              href={busy ? undefined : deskPageUrl('edit.php', { id: expense.id })}
              className={`exp-desk-btn exp-desk-btn-secondary exp-desk-quick-action-btn${busy ? ' is-disabled' : ''}`}
              aria-disabled={busy}
              onClick={(event) => {
                if (busy) event.preventDefault();
              }}
            >
              <Pencil size={16} aria-hidden="true" />
              Edit draft
            </a>
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
