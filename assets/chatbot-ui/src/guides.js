export const LOCAL_GUIDES = [
  {
    id: 'overview',
    title: 'System Overview',
    answer:
      'This Payment Voucher System lets employees create vouchers, route approvals (Dept Manager, Finance Checked By, GM), and finalize them. Statuses: Draft, Pending, Posted, Paid.',
  },
  {
    id: 'create_voucher',
    title: 'Creating a Voucher',
    answer:
      'Go to Create Voucher, fill payee, description, add items with Payment Type, Budget Type, Amount. Select Dept Manager + Checked By then submit or save Draft.',
  },
  {
    id: 'budget_types',
    title: 'Budget Types',
    answer:
      'Operational Expenses; Procurement & Supplies; Employee Costs; Sales & Marketing; Logistics & Delivery; Administration & Management; Projects & Capital Expenditure (CAPEX); Financial Obligations; Tax & Compliance; Others / Miscellaneous.',
  },
  {
    id: 'approvals',
    title: 'Approval Flow',
    answer:
      'Employee creates ? Department Manager ? Finance (Checked By) ? General Manager final approval ? Posted ? Paid.',
  },
  {
    id: 'voucher_statuses',
    title: 'Voucher Statuses',
    answer:
      'Draft (incomplete) ? Pending (submitted) ? Posted (accounting recorded) ? Paid (funds disbursed).',
  },
  {
    id: 'reports',
    title: 'Reports',
    answer: 'Admins filter vouchers by date/status/budget. Export for accounting.',
  },
  {
    id: 'attachments',
    title: 'Attachments',
    answer: 'Match Supporting Documents count with uploaded invoice/receipt references.',
  },
  {
    id: 'notifications',
    title: 'Notifications',
    answer: 'Bell shows system events, messages icon for direct communication. Badges = unread.',
  },
  {
    id: 'drafts',
    title: 'Drafts',
    answer: 'Use the disk icon to save progress; later open draft and submit.',
  },
  {
    id: 'security',
    title: 'Security',
    answer: 'Use strong password; log out; keep financial data internal.',
  },
];

export function localSearch(query) {
  const q = String(query || '').toLowerCase().trim();
  if (!q) return [];
  const tokens = q.split(/\s+/).filter(Boolean);
  const scored = [];

  LOCAL_GUIDES.forEach((guide) => {
    let score = 0;
    const title = guide.title.toLowerCase();
    const answer = guide.answer.toLowerCase();
    if (title.includes(q)) score += 3;
    if (answer.includes(q)) score += 1;
    tokens.forEach((token) => {
      if (token && answer.includes(token)) score += 1;
    });
    if (score > 0) scored.push({ score, guide });
  });

  scored.sort((a, b) => b.score - a.score);
  return scored.slice(0, 3).map((item) => item.guide);
}
