const STATUS_MAP = {
  pending: { label: 'Pending', className: 'exp-desk-badge exp-desk-badge--unposted' },
  approved: { label: 'Approved', className: 'exp-desk-badge exp-desk-badge--posted' },
  rejected: { label: 'Rejected', className: 'exp-desk-badge exp-desk-badge--rejected' },
  cancelled: { label: 'Cancelled', className: 'exp-desk-badge exp-desk-badge--draft' },
};

export default function PettyCashStatusBadge({ status }) {
  const key = String(status || '').toLowerCase();
  const meta = STATUS_MAP[key] || { label: status || '-', className: 'exp-desk-badge exp-desk-badge--draft' };

  return (
    <span className={meta.className}>
      {meta.label}
    </span>
  );
}
