import { useCallback, useEffect, useMemo, useState } from 'react';
import { Loader2 } from 'lucide-react';
import PettyCashPageShell from '../components/PettyCashPageShell.jsx';
import PettyCashStatusBadge from '../components/PettyCashStatusBadge.jsx';
import {
  deskPageUrl,
  fetchReplenishmentsList,
  postReplenishmentAction,
} from '../api/pettyCashDesk.js';
import { formatDate, formatMoney, parseDateValue, currentYear } from '../utils/format.js';

function defaultYearFilters() {
  const year = String(currentYear());
  return { year, date_from: `${year}-01-01`, date_to: `${year}-12-31`, status: '' };
}

function readInitialFilters() {
  if (typeof window === 'undefined') {
    return defaultYearFilters();
  }
  const params = new URLSearchParams(window.location.search);
  if (params.get('all_years') === '1') {
    return { year: '', date_from: '', date_to: '', status: params.get('status') || '' };
  }
  const hasExplicitDateFilter = params.has('year') || params.has('date_from') || params.has('date_to');
  const year = params.get('year') || (hasExplicitDateFilter ? '' : String(currentYear()));
  let dateFrom = params.get('date_from') || '';
  let dateTo = params.get('date_to') || '';
  if (year && !dateFrom && !dateTo) {
    dateFrom = `${year}-01-01`;
    dateTo = `${year}-12-31`;
  }
  return {
    year,
    date_from: dateFrom,
    date_to: dateTo,
    status: params.get('status') || '',
  };
}

function buildYearPillUrl(year) {
  const params = new URLSearchParams(window.location.search);
  params.set('module', params.get('module') || 'petty_cash');
  ['year', 'date_from', 'date_to', 'status', 'all_years'].forEach((key) => params.delete(key));
  if (year) {
    params.set('year', String(year));
  } else {
    params.set('all_years', '1');
  }
  const qs = params.toString();
  return `${window.location.pathname}${qs ? `?${qs}` : ''}`;
}

function isRowActionTarget(target) {
  if (!(target instanceof Element)) return false;
  return Boolean(
    target.closest('a, button, input, select, textarea, label, [data-exp-row-ignore]'),
  );
}

function replenishmentViewUrl(rep) {
  return rep.view_url || rep.confirm_url || deskPageUrl('replenishments/confirm-approve.php', { rep_id: rep.id, view: '1' });
}

export default function PettyCashReplenishmentsListPage() {
  const [rows, setRows] = useState([]);
  const [canManage, setCanManage] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState(0);
  const [filters, setFilters] = useState(readInitialFilters);

  const availableYears = useMemo(() => {
    const years = new Set();
    rows.forEach((row) => {
      const source = row.approved_at || row.created_at;
      const date = parseDateValue(source);
      if (!date) return;
      years.add(date.getFullYear());
    });
    if (years.size === 0) {
      [currentYear(), currentYear() - 1].forEach((y) => years.add(y));
    }
    return Array.from(years).sort((a, b) => b - a);
  }, [rows]);

  const load = useCallback(async (activeFilters) => {
    setLoading(true);
    setError('');
    try {
      const params = { limit: 500 };
      if (activeFilters.year) params.year = activeFilters.year;
      if (activeFilters.date_from) params.date_from = activeFilters.date_from;
      if (activeFilters.date_to) params.date_to = activeFilters.date_to;
      if (activeFilters.status) params.status = activeFilters.status;
      const data = await fetchReplenishmentsList(params);
      setRows(data.data || []);
      setCanManage(Boolean(data.can_manage));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load top-ups.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load(filters);
  }, [filters, load]);

  function applyYear(year) {
    const next = year
      ? { year: String(year), date_from: `${year}-01-01`, date_to: `${year}-12-31`, status: '' }
      : { year: '', date_from: '', date_to: '', status: '' };
    setFilters(next);
    if (typeof window !== 'undefined') {
      window.history.replaceState(null, '', buildYearPillUrl(year));
    }
  }

  async function runAction(action, id) {
    setBusyId(id);
    try {
      await postReplenishmentAction(action, id);
      await load(filters);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Action failed.');
    } finally {
      setBusyId(0);
    }
  }

  const activeYear = filters.year ? Number(filters.year) : 0;
  const filterLabel = activeYear > 0 ? `Showing ${rows.length} record${rows.length === 1 ? '' : 's'} from ${activeYear}` : `${rows.length} record${rows.length === 1 ? '' : 's'}`;
  const showActionsColumn = canManage && rows.some((rep) => rep.status === 'pending');

  return (
    <PettyCashPageShell title="Top-up requests">
      {error ? <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error}</div> : null}
      <div style={{ marginBottom: '0.75rem', display: 'flex', flexWrap: 'wrap', gap: '0.5rem', alignItems: 'center' }}>
        <a href={deskPageUrl('replenishment.php')} className="exp-desk-btn exp-desk-btn-primary">New request</a>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.35rem', alignItems: 'center', marginLeft: 'auto' }}>
          <span style={{ fontSize: '0.75rem', fontWeight: 600, color: '#64748b', textTransform: 'uppercase' }}>Year</span>
          <button
            type="button"
            className={`exp-desk-btn${activeYear <= 0 ? ' exp-desk-btn-primary' : ''}`}
            style={{ padding: '0.35rem 0.75rem', fontSize: '0.8125rem' }}
            onClick={() => applyYear(0)}
          >
            All
          </button>
          {availableYears.map((year) => (
            <button
              key={year}
              type="button"
              className={`exp-desk-btn${activeYear === year ? ' exp-desk-btn-primary' : ''}`}
              style={{ padding: '0.35rem 0.75rem', fontSize: '0.8125rem' }}
              onClick={() => applyYear(year)}
            >
              {year}
            </button>
          ))}
        </div>
      </div>
      <p className="exp-desk-footnote" style={{ margin: '0 0 0.75rem', fontSize: '0.8125rem', color: '#64748b' }}>{filterLabel}</p>
      {loading ? (
        <div className="exp-desk-loading"><Loader2 className="exp-desk-boot-spinner" /><span>Loading...</span></div>
      ) : (
        <section className="exp-desk-results">
          <div className="exp-desk-table-wrap">
            <table className="exp-desk-table pc-voucher-table">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Requested</th>
                  <th>Approved</th>
                  <th>Custodian</th>
                  <th>Amount</th>
                  <th>Status</th>
                  {showActionsColumn ? <th aria-label="Actions" /> : null}
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 ? (
                  <tr>
                    <td colSpan={showActionsColumn ? 7 : 6} style={{ textAlign: 'center', color: '#94a3b8', padding: '2rem 0.75rem' }}>
                      {activeYear > 0 ? `No top-up requests found for ${activeYear}.` : 'No top-up requests found.'}
                    </td>
                  </tr>
                ) : rows.map((rep) => {
                  const viewUrl = replenishmentViewUrl(rep);
                  const showRowActions = showActionsColumn && rep.status === 'pending';

                  return (
                  <tr
                    key={rep.id}
                    className="exp-desk-row-clickable"
                    tabIndex={0}
                    onClick={(event) => {
                      if (isRowActionTarget(event.target)) return;
                      window.location.href = viewUrl;
                    }}
                    onKeyDown={(event) => {
                      if (event.key !== 'Enter' && event.key !== ' ') return;
                      if (isRowActionTarget(event.target)) return;
                      event.preventDefault();
                      window.location.href = viewUrl;
                    }}
                  >
                    <td>
                      <a href={viewUrl} className="exp-desk-ref">{rep.replenishment_number}</a>
                    </td>
                    <td>{formatDate(rep.created_at)}</td>
                    <td>{rep.approved_at ? formatDate(rep.approved_at) : '—'}</td>
                    <td>{rep.custodian_name}</td>
                    <td className="exp-desk-amt">{formatMoney(rep.amount)}</td>
                    <td><PettyCashStatusBadge status={rep.status} /></td>
                    {showActionsColumn ? (
                      <td className="exp-desk-row-actions" data-exp-row-ignore>
                        {showRowActions ? (
                          <div className="pc-voucher-table__actions">
                            <a href={rep.confirm_url} className="pc-voucher-card__btn pc-voucher-card__btn--approve">
                              Approve
                            </a>
                            <button
                              type="button"
                              className="pc-voucher-card__btn pc-voucher-card__btn--reject"
                              disabled={busyId === rep.id}
                              onClick={() => runAction('reject_replenishment', rep.id)}
                            >
                              Reject
                            </button>
                          </div>
                        ) : null}
                      </td>
                    ) : null}
                  </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </PettyCashPageShell>
  );
}
