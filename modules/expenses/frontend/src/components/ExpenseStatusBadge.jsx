export function isDraftExpense(expense) {
  if (!expense) return false;
  if (Number(expense.is_posted) === 1) return false;
  if (expense.can_delete === true || expense.can_delete === 1) return true;
  if (expense.can_edit === true || expense.can_edit === 1) return true;

  const display = String(expense.display_status || '').toLowerCase();
  if (display === 'draft') return true;

  return String(expense.status || '').toLowerCase().trim() === 'draft';
}

export function canDeleteDraftExpense(expense) {
  return isDraftExpense(expense);
}

export function expenseStatusLabel(expense) {
  const display = String(expense?.display_status || '').toLowerCase();
  if (display === 'posted') return 'Posted';
  if (display === 'draft') return 'Draft';

  const status = String(expense?.status || '').toLowerCase();
  if (status === 'draft') return 'Draft';
  if (Number(expense?.is_posted) === 1 || status === 'posted') return 'Posted';

  return 'Draft';
}

function badgeClass(expense) {
  const label = expenseStatusLabel(expense);
  if (label === 'Posted') return 'exp-desk-badge--posted';
  return 'exp-desk-badge--draft';
}

export default function ExpenseStatusBadge({ expense }) {
  const label = expenseStatusLabel(expense);
  return (
    <span className={`exp-desk-badge ${badgeClass(expense)}`}>
      {label}
    </span>
  );
}
