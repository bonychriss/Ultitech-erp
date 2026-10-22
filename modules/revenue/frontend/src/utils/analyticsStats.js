const STATUS_BREAKDOWN_META = [
  { key: 'paid', label: 'Paid', color: '#22c55e' },
  { key: 'partial', label: 'Partial', color: '#3b82f6' },
  { key: 'pending', label: 'Pending', color: '#f59e0b' },
  { key: 'unpaid', label: 'Unpaid', color: '#ef4444' },
  { key: 'uploaded', label: 'Uploaded', color: '#a855f7' },
];

function entryDay(row) {
  const raw = String(row?.entry_date || '').trim();
  if (!raw) return '';
  const day = raw.includes('T') ? raw.slice(0, 10) : raw.slice(0, 10);
  return /^\d{4}-\d{2}-\d{2}$/.test(day) ? day : '';
}

/**
 * Status donut data for the rows currently listed in the desk table.
 */
export function buildRevenueStatusBreakdown(rows) {
  const counts = Object.create(null);
  for (const row of rows || []) {
    const key = String(row?.status_class || 'unpaid').toLowerCase();
    counts[key] = (counts[key] || 0) + 1;
  }
  return STATUS_BREAKDOWN_META
    .map((meta) => ({ ...meta, count: counts[meta.key] || 0 }))
    .filter((row) => row.count > 0);
}

/**
 * Trend series from listed revenue rows (total amounts by day/month).
 * Mirrors the expenses desk trend windowing rules.
 */
export function buildRevenueTrendStats(rows) {
  const now = new Date();
  const currentMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  const monthStart = `${currentMonth}-01`;
  const monthEndDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
  const monthEnd = `${currentMonth}-${String(monthEndDate.getDate()).padStart(2, '0')}`;

  const byDay = Object.create(null);
  const dates = [];
  let monthTotal = 0;

  for (const row of rows || []) {
    const day = entryDay(row);
    if (!day) continue;
    const amount = Math.max(0, Number(row.amount_total) || 0);
    if (amount <= 0) continue;
    byDay[day] = (byDay[day] || 0) + amount;
    dates.push(day);
    if (day.startsWith(currentMonth)) {
      monthTotal += amount;
    }
  }

  let trendFrom = monthStart;
  let trendTo = monthEnd;

  if (monthTotal <= 0 && dates.length > 0) {
    dates.sort();
    const latest = dates[dates.length - 1];
    const earliest = dates[0];
    const latestMonth = latest.slice(0, 7);
    const [ly, lm] = latestMonth.split('-').map(Number);
    const windowStartDate = new Date(ly, lm - 1 - 5, 1);
    const windowStart = `${windowStartDate.getFullYear()}-${String(windowStartDate.getMonth() + 1).padStart(2, '0')}-01`;
    trendFrom = earliest > windowStart ? earliest : windowStart;
    trendTo = latest;
    trendFrom = `${trendFrom.slice(0, 7)}-01`;
    const [ty, tm] = trendTo.slice(0, 7).split('-').map(Number);
    const endOfTo = new Date(ty, tm, 0);
    trendTo = `${ty}-${String(tm).padStart(2, '0')}-${String(endOfTo.getDate()).padStart(2, '0')}`;
  }

  const fromTs = Date.parse(`${trendFrom}T12:00:00`);
  const toTs = Date.parse(`${trendTo}T12:00:00`);
  if (!Number.isFinite(fromTs) || !Number.isFinite(toTs) || fromTs > toTs) {
    trendFrom = monthStart;
    trendTo = monthEnd;
  }

  const from = Date.parse(`${trendFrom}T12:00:00`);
  const to = Date.parse(`${trendTo}T12:00:00`);
  const daySpan = Math.round((to - from) / 86400000) + 1;
  const useMonthly = daySpan > 45;
  const buckets = [];

  if (useMonthly) {
    const cursor = new Date(from);
    cursor.setDate(1);
    const endMonth = new Date(to);
    endMonth.setDate(1);
    while (cursor <= endMonth) {
      const y = cursor.getFullYear();
      const m = cursor.getMonth() + 1;
      const key = `${y}-${String(m).padStart(2, '0')}`;
      buckets.push({
        key,
        date: `${key}-01`,
        label: cursor.toLocaleDateString('en-US', { month: 'short', year: 'numeric' }),
        amount: 0,
      });
      cursor.setMonth(cursor.getMonth() + 1);
    }
    const index = Object.create(null);
    buckets.forEach((b, i) => { index[b.key] = i; });
    Object.entries(byDay).forEach(([day, amount]) => {
      if (day < trendFrom || day > trendTo) return;
      const key = day.slice(0, 7);
      if (index[key] != null) buckets[index[key]].amount += amount;
    });
  } else {
    const cursor = new Date(from);
    const end = new Date(to);
    while (cursor <= end) {
      const y = cursor.getFullYear();
      const m = String(cursor.getMonth() + 1).padStart(2, '0');
      const d = String(cursor.getDate()).padStart(2, '0');
      const day = `${y}-${m}-${d}`;
      buckets.push({
        date: day,
        label: cursor.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
        amount: byDay[day] || 0,
      });
      cursor.setDate(cursor.getDate() + 1);
    }
  }

  return {
    trend: buckets.map(({ date, label, amount }) => ({ date, label, amount })),
    trend_from: trendFrom,
    trend_to: trendTo,
  };
}
