export function isDraftExpense(expense) {
  if (!expense) return false;
  if (Number(expense.is_posted) === 1) return false;
  if (expense.is_voucher || expense.source_type === 'voucher') return false;
  if (expense.can_delete === true || expense.can_delete === 1) return true;
  if (expense.can_edit === true || expense.can_edit === 1) return true;

  const display = String(expense.display_status || '').toLowerCase();
  if (display === 'draft') return true;

  return String(expense.status || '').toLowerCase().trim() === 'draft';
}

export function canDeleteDraftExpense(expense) {
  return isDraftExpense(expense);
}

export function isVoucherExpense(expense) {
  return Boolean(expense?.is_voucher) || String(expense?.source_type || '') === 'voucher';
}

export function expenseStatusLabel(expense) {
  const display = String(expense?.display_status || '').toLowerCase();
  if (display === 'posted') return 'Posted';
  if (display === 'paid') return 'Paid';
  if (display === 'approved') return 'Approved';
  if (display === 'rejected') return 'Rejected';
  if (display === 'pending') return 'Pending';
  if (display === 'confirming') return 'Confirming';
  if (display === 'draft') return 'Draft';

  const status = String(expense?.status || '').toLowerCase();
  if (Number(expense?.is_posted) === 1 || status === 'posted') return 'Posted';
  if (Number(expense?.is_paid) === 1 || status === 'paid') return 'Paid';
  if (status === 'approved') return 'Approved';
  if (status === 'rejected') return 'Rejected';
  if (status === 'pending') return 'Pending';
  if (status === 'confirming') return 'Confirming';
  if (status === 'draft') return 'Draft';

  return isVoucherExpense(expense) ? 'Pending' : 'Draft';
}

function badgeClass(expense) {
  const label = expenseStatusLabel(expense);
  if (label === 'Posted' || label === 'Paid') return 'exp-desk-badge--posted';
  if (label === 'Approved') return 'exp-desk-badge--unposted';
  if (label === 'Rejected') return 'exp-desk-badge--rejected';
  if (label === 'Pending' || label === 'Confirming') return 'exp-desk-badge--unposted';
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
