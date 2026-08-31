export function revenueStatusLabel(entry) {
  return String(entry?.status_label || 'Unpaid');
}

function badgeClass(entry) {
  const cls = String(entry?.status_class || 'unpaid').toLowerCase();
  if (cls === 'paid') return 'rev-desk-badge--paid';
  if (cls === 'partial') return 'rev-desk-badge--partial';
  if (cls === 'pending') return 'rev-desk-badge--pending';
  return 'rev-desk-badge--unpaid';
}

export default function RevenueStatusBadge({ entry }) {
  const label = revenueStatusLabel(entry);
  return (
    <span className={`rev-desk-badge ${badgeClass(entry)}`}>
      {label}
    </span>
  );
}
