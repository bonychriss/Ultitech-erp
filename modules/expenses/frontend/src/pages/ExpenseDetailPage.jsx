import { useCallback, useEffect, useState } from 'react';
import { ArrowLeft, CheckCircle2, Loader2, Pencil, Trash2 } from 'lucide-react';
import ExpenseDetailTable from '../components/ExpenseDetailTable';
import ExpenseStatusBadge, { canDeleteDraftExpense } from '../components/ExpenseStatusBadge';
import {
  deleteDraftExpense,
  fetchPostDraftPreview,
  postDraftExpense,
  deskPageUrl,
  fetchExpenseDetail,
} from '../api/expensesDesk';

const expenseId = Number(window.__EXPENSES_DETAIL_ID__ || 0);

export default function ExpenseDetailPage() {
  const [expense, setExpense] = useState(null);
  const [csrfToken, setCsrfToken] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const loadExpense = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const result = await fetchExpenseDetail(expenseId);
      setExpense(result.data);
      setCsrfToken(result.csrf_token || '');
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : 'Could not load expense details.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadExpense();
  }, [loadExpense]);

  async function handlePost() {
    if (!expense || !csrfToken) return;
    setBusy(true);
    setError('');
    try {
      const result = await fetchPostDraftPreview(expense.id);
      const preview = result.preview;
      const amount = Number(preview.amount || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      });
      const account = preview.source_account || {};
      const confirmText = [
        `Post ${preview.expense_number || expense.expense_number} to the ledger?`,
        `Amount: ${preview.currency_code} ${amount}`,
        `Paid from: ${account.name || 'the selected account'}`,
      ].join('\n');
      if (!window.confirm(confirmText)) return;
      await postDraftExpense(expense.id, csrfToken);
      await loadExpense();
    } catch (actionError) {
      setError(actionError instanceof Error ? actionError.message : 'Could not post this expense.');
    } finally {
      setBusy(false);
    }
  }

  async function handleDelete() {
    if (!expense || !csrfToken) return;
    const label = expense.expense_number || `expense #${expense.id}`;
    if (!window.confirm(`Delete ${label}? This cannot be undone.`)) return;
    setBusy(true);
    setError('');
    try {
      await deleteDraftExpense(expense.id, csrfToken);
      window.location.href = deskPageUrl('index.php');
    } catch (actionError) {
      setError(actionError instanceof Error ? actionError.message : 'Could not delete this draft.');
      setBusy(false);
    }
  }

  const isDraft = canDeleteDraftExpense(expense);

  return (
    <div className="exp-detail-page">
      <div className="exp-detail-toolbar">
        <a className="exp-desk-btn exp-desk-btn-secondary" href={deskPageUrl('index.php')}>
          <ArrowLeft size={16} aria-hidden="true" />
          Back to Expenses
        </a>
        {!loading && expense ? <ExpenseStatusBadge expense={expense} /> : null}
      </div>

      {loading ? (
        <div className="exp-detail-message"><Loader2 size={18} className="animate-spin" />Loading expense details...</div>
      ) : error && !expense ? (
        <div className="exp-detail-message exp-detail-message--error" role="alert">{error}</div>
      ) : expense ? (
        <>
          {error ? <div className="exp-detail-alert" role="alert">{error}</div> : null}
          {isDraft ? (
            <div className="exp-detail-actions">
              <button type="button" className="exp-desk-btn exp-desk-btn-primary" onClick={handlePost} disabled={busy}>
                {busy ? <Loader2 size={15} className="animate-spin" /> : <CheckCircle2 size={15} />}
                Post expense
              </button>
              <a
                className="exp-desk-btn exp-desk-btn-secondary"
                href={deskPageUrl('edit.php', { id: expense.id })}
              >
                <Pencil size={15} aria-hidden="true" />
                Edit draft
              </a>
              <button
                type="button"
                className="exp-desk-btn exp-desk-btn-secondary exp-desk-quick-action-btn--danger"
                onClick={handleDelete}
                disabled={busy}
              >
                <Trash2 size={15} aria-hidden="true" />
                Delete draft
              </button>
            </div>
          ) : null}
          <ExpenseDetailTable />
        </>
      ) : null}
    </div>
  );
}
