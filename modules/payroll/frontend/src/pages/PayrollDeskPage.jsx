import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  CalendarCheck,
  CheckCircle2,
  History,
  Inbox,
  Loader2,
  Plus,
  Search,
  Settings2,
  Trash2,
  Users,
  Wallet,
  X,
} from 'lucide-react';
import {
  approveRun,
  buildViewRunUrl,
  deleteRun,
  deskPageUrl,
  fetchDeskInit,
  formatMoney,
} from '../api/payrollDesk';

function StatusBadge({ status }) {
  const cls = {
    paid: 'pay-desk-badge--paid',
    approved: 'pay-desk-badge--approved',
    draft: 'pay-desk-badge--draft',
  }[status] || 'pay-desk-badge--draft';

  const label = {
    paid: 'Paid',
    approved: 'Approved',
    draft: 'Draft',
  }[status] || status;

  return <span className={`pay-desk-badge ${cls}`}>{label}</span>;
}

function matchesRun(run, query) {
  const q = String(query || '').trim().toLowerCase();
  if (!q) return true;
  return (
    String(run.periodLabel || '').toLowerCase().includes(q)
    || String(run.runDateLabel || '').toLowerCase().includes(q)
    || String(run.status || '').toLowerCase().includes(q)
  );
}

function isRowActionTarget(target) {
  if (!(target instanceof Element)) return false;
  return Boolean(target.closest('a, button, input, select, textarea, label, [data-pay-row-ignore]'));
}

export default function PayrollDeskPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [confirm, setConfirm] = useState(null);
  const [actionId, setActionId] = useState(null);
  const [search, setSearch] = useState('');
  const [suggestionsOpen, setSuggestionsOpen] = useState(false);
  const [activeSuggestion, setActiveSuggestion] = useState(-1);
  const [glowRunId, setGlowRunId] = useState(0);
  const searchWrapRef = useRef(null);
  const glowRowRef = useRef(null);

  const loadData = useCallback(async (silent = false) => {
    if (!silent) setLoading(true);
    setError('');
    try {
      const data = await fetchDeskInit();
      setInit(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load payroll.');
    } finally {
      if (!silent) setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  useEffect(() => {
    const onDown = (event) => {
      if (!searchWrapRef.current?.contains(event.target)) {
        setSuggestionsOpen(false);
        setActiveSuggestion(-1);
      }
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, []);

  useEffect(() => {
    if (!notice) return undefined;
    const timer = window.setTimeout(() => setNotice(''), 4500);
    return () => window.clearTimeout(timer);
  }, [notice]);

  useEffect(() => {
    if (!glowRunId) return undefined;
    const row = glowRowRef.current;
    if (row) {
      row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    const clearGlow = () => setGlowRunId(0);
    const timer = window.setTimeout(clearGlow, 6000);
    document.addEventListener('click', clearGlow, { once: true });
    return () => {
      window.clearTimeout(timer);
      document.removeEventListener('click', clearGlow);
    };
  }, [glowRunId, init]);

  const links = init?.links || {};
  const stats = init?.stats || {};
  const allRuns = init?.runs || [];
  const user = init?.user || {};
  const missingTables = init?.missingTables || [];
  const setupRequired = missingTables.length > 0;

  const runs = useMemo(() => {
    return allRuns.filter((run) => matchesRun(run, search));
  }, [allRuns, search]);

  const suggestions = useMemo(() => {
    const q = search.trim();
    if (q.length < 1) return [];
    return allRuns.filter((run) => matchesRun(run, q)).slice(0, 8);
  }, [allRuns, search]);

  const openRun = useCallback((runId) => {
    window.location.href = buildViewRunUrl(runId, links);
  }, [links]);

  const handleApprove = useCallback(async (id) => {
    setActionId(id);
    setError('');
    try {
      const res = await approveRun(id);
      setInit(res.data || init);
      setNotice(res.message || 'Payroll run approved.');
      setGlowRunId(Number(id) || 0);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Approval failed.');
    } finally {
      setActionId(null);
    }
  }, [init]);

  const handleDelete = useCallback(async (id) => {
    setActionId(id);
    setError('');
    try {
      const res = await deleteRun(id);
      setInit(res.data || init);
      setNotice(res.message || 'Draft deleted.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Delete failed.');
    } finally {
      setActionId(null);
    }
  }, [init]);

  function onSearchKeyDown(event) {
    if (!suggestionsOpen || suggestions.length === 0) {
      if (event.key === 'Escape') setSuggestionsOpen(false);
      return;
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActiveSuggestion((i) => (i + 1) % suggestions.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActiveSuggestion((i) => (i <= 0 ? suggestions.length - 1 : i - 1));
    } else if (event.key === 'Enter' && activeSuggestion >= 0 && suggestions[activeSuggestion]) {
      event.preventDefault();
      openRun(suggestions[activeSuggestion].id);
    } else if (event.key === 'Escape') {
      setSuggestionsOpen(false);
      setActiveSuggestion(-1);
    }
  }

  if (loading && !init) {
    return (
      <div className="pay-desk-page pay-desk-boot-loading" role="status" aria-live="polite">
        <Loader2 className="pay-desk-boot-spinner" aria-hidden="true" />
        <span>Loading payroll...</span>
      </div>
    );
  }

  return (
    <div className="pay-desk-page">
      <div className="pay-desk-page-header pay-desk-page-header--desk">
        <div className="pay-desk-page-header-search" ref={searchWrapRef}>
          <div className={`pay-desk-search-field${suggestionsOpen && search.trim() ? ' has-suggestions' : ''}`}>
            <Search className="pay-desk-search-icon" aria-hidden="true" size={16} />
            <input
              type="search"
              className="pay-desk-search-input"
              placeholder="Search payroll runs by period or status..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setSuggestionsOpen(true);
                setActiveSuggestion(0);
              }}
              onFocus={() => {
                if (search.trim().length >= 1) setSuggestionsOpen(true);
              }}
              onKeyDown={onSearchKeyDown}
              aria-label="Search payroll runs"
              aria-autocomplete="list"
              aria-expanded={suggestionsOpen}
              aria-controls="pay-desk-run-suggestions"
            />
            {suggestionsOpen && search.trim().length >= 1 && (
              <div
                id="pay-desk-run-suggestions"
                className="pay-desk-suggestions"
                role="listbox"
                aria-label="Payroll run suggestions"
              >
                {suggestions.length === 0 ? (
                  <div className="pay-desk-suggestion-empty">No runs found</div>
                ) : (
                  suggestions.map((run, index) => {
                    const isActive = index === activeSuggestion;
                    return (
                      <button
                        key={run.id}
                        type="button"
                        role="option"
                        aria-selected={isActive}
                        className={`pay-desk-suggestion${isActive ? ' is-active' : ''}`}
                        onMouseEnter={() => setActiveSuggestion(index)}
                        onClick={() => openRun(run.id)}
                      >
                        <div className="pay-desk-suggestion-meta">
                          <div className="pay-desk-suggestion-name">{run.periodLabel}</div>
                          <div className="pay-desk-suggestion-code">
                            {run.runDateLabel || '-'}
                            {' | '}
                            {String(run.status || 'draft').toUpperCase()}
                          </div>
                        </div>
                        <div className="pay-desk-suggestion-price">
                          {formatMoney(run.totalPayout)}
                        </div>
                      </button>
                    );
                  })
                )}
              </div>
            )}
          </div>
        </div>

        <div className="pay-desk-page-header-actions">
          <a href={links.settings || deskPageUrl('settings.php')} className="pay-desk-btn pay-desk-btn-secondary">
            <Settings2 size={15} aria-hidden="true" />
            Settings
          </a>
          <a
            href={links.runPayroll || deskPageUrl('run_payroll.php')}
            className="pay-desk-btn pay-desk-btn-primary"
          >
            <Plus size={16} aria-hidden="true" />
            Run payroll
          </a>
        </div>
      </div>

      {setupRequired && (
        <div className="pay-desk-setup" role="alert">
          <h2>Payroll setup required</h2>
          <p>Missing tables: <strong>{missingTables.join(', ')}</strong></p>
          <a href={links.setup || deskPageUrl('setup.php')} className="pay-desk-btn pay-desk-btn-primary">
            Run payroll setup
          </a>
        </div>
      )}

      {error && <div className="pay-desk-flash-error" role="alert">{error}</div>}

      {notice && (
        <div className="pay-desk-flash-ok" role="status">
          <span>{notice}</span>
          <button type="button" className="pay-desk-flash-dismiss" onClick={() => setNotice('')} aria-label="Dismiss">
            <X size={14} aria-hidden="true" />
          </button>
        </div>
      )}

      <section className="pay-desk-kpi-grid" aria-label="Summary">
        <div className="pay-desk-kpi-card">
          <div className="pay-desk-kpi">
            <div className="pay-desk-kpi-icon pay-desk-kpi-icon--violet">
              <Users size={20} aria-hidden="true" />
            </div>
            <div className="pay-desk-kpi-body">
              <div className="pay-desk-kpi-label">staff with salaries</div>
              <div className="pay-desk-kpi-value">{stats.total_salaried_staff ?? 0}</div>
            </div>
          </div>
        </div>
        <div className="pay-desk-kpi-card">
          <div className="pay-desk-kpi">
            <div className="pay-desk-kpi-icon pay-desk-kpi-icon--indigo">
              <CalendarCheck size={20} aria-hidden="true" />
            </div>
            <div className="pay-desk-kpi-body">
              <div className="pay-desk-kpi-label">last run</div>
              <div className="pay-desk-kpi-value">{stats.last_run_period || '-'}</div>
            </div>
          </div>
        </div>
        <div className="pay-desk-kpi-card">
          <div className="pay-desk-kpi">
            <div className="pay-desk-kpi-icon pay-desk-kpi-icon--amber">
              <History size={20} aria-hidden="true" />
            </div>
            <div className="pay-desk-kpi-body">
              <div className="pay-desk-kpi-label">total runs</div>
              <div className="pay-desk-kpi-value">{stats.total_runs ?? 0}</div>
            </div>
          </div>
        </div>
        <div className="pay-desk-kpi-card">
          <div className="pay-desk-kpi">
            <div className="pay-desk-kpi-icon pay-desk-kpi-icon--teal">
              <Wallet size={20} aria-hidden="true" />
            </div>
            <div className="pay-desk-kpi-body">
              <div className="pay-desk-kpi-label">listed now</div>
              <div className="pay-desk-kpi-value">{runs.length}</div>
              <div className="pay-desk-kpi-helper">matching current filters</div>
            </div>
          </div>
        </div>
      </section>

      <section className="pay-desk-results">
        <div className="pay-desk-results-head">
          <span className="pay-desk-results-count">
            {runs.length} {runs.length === 1 ? 'result' : 'results'}
          </span>
        </div>

        {setupRequired ? (
          <div className="pay-desk-empty">
            <p className="pay-desk-empty-title">Complete setup to view payroll history</p>
          </div>
        ) : runs.length === 0 ? (
          <div className="pay-desk-empty">
            <Inbox className="pay-desk-empty-icon" aria-hidden="true" />
            <p className="pay-desk-empty-title">No payroll runs found</p>
            <p className="pay-desk-empty-sub">
              {search
                ? 'Try adjusting your search.'
                : (
                  <a href={links.runPayroll || deskPageUrl('run_payroll.php')}>Run your first payroll</a>
                )}
            </p>
          </div>
        ) : (
          <div className="pay-desk-table-wrap">
            <table className="pay-desk-table">
              <thead>
                <tr>
                  <th>Period</th>
                  <th>Run date</th>
                  <th>Status</th>
                  <th>Total payout</th>
                  <th style={{ textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {runs.map((run) => {
                  const glowing = glowRunId === run.id;
                  return (
                    <tr
                      key={run.id}
                      ref={glowing ? glowRowRef : null}
                      className={`pay-desk-row-clickable${glowing ? ' is-glow' : ''}`}
                      tabIndex={0}
                      onClick={(event) => {
                        if (isRowActionTarget(event.target)) return;
                        openRun(run.id);
                      }}
                      onKeyDown={(event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                          event.preventDefault();
                          openRun(run.id);
                        }
                      }}
                    >
                      <td>
                        <span className="pay-desk-name">{run.periodLabel}</span>
                      </td>
                      <td>
                        <div className="pay-desk-cell-sub">{run.runDateLabel || '-'}</div>
                      </td>
                      <td>
                        <StatusBadge status={run.status} />
                      </td>
                      <td className="pay-desk-amt">{formatMoney(run.totalPayout)}</td>
                      <td style={{ textAlign: 'right' }} data-pay-row-ignore>
                        <div className="pay-desk-actions">
                          {run.status === 'draft' && user.isAdmin ? (
                            <>
                              <button
                                type="button"
                                className="pay-desk-icon-btn pay-desk-icon-btn--approve"
                                title="Approve"
                                disabled={actionId === run.id}
                                onClick={() => setConfirm({ type: 'approve', id: run.id, period: run.periodLabel })}
                              >
                                <CheckCircle2 size={16} aria-hidden="true" />
                              </button>
                              <button
                                type="button"
                                className="pay-desk-icon-btn pay-desk-icon-btn--del"
                                title="Delete draft"
                                disabled={actionId === run.id}
                                onClick={() => setConfirm({ type: 'delete', id: run.id, period: run.periodLabel })}
                              >
                                <Trash2 size={16} aria-hidden="true" />
                              </button>
                            </>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {confirm && (
        <div className="pay-desk-modal-backdrop" role="presentation" onClick={() => setConfirm(null)}>
          <div
            className="pay-desk-modal pay-desk-confirm-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="pay-desk-confirm-title"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="pay-salary-edit-modal-head">
              <h2 id="pay-desk-confirm-title" className="pay-salary-edit-modal-title">
                {confirm.type === 'delete' ? 'Delete draft?' : 'Approve payroll run?'}
              </h2>
              <button
                type="button"
                className="pay-salary-edit-modal-close"
                onClick={() => setConfirm(null)}
                aria-label="Close"
              >
                <X size={18} aria-hidden="true" />
              </button>
            </div>
            <div className="pay-desk-confirm-body">
              <p>
                {confirm.type === 'delete'
                  ? `All payslips for ${confirm.period} will be removed.`
                  : `This will finalize figures for ${confirm.period}.`}
              </p>
              <div className="pay-desk-confirm-actions">
                <button type="button" className="pay-desk-btn pay-desk-btn-secondary" onClick={() => setConfirm(null)}>
                  Cancel
                </button>
                <button
                  type="button"
                  className={`pay-desk-btn ${confirm.type === 'delete' ? 'pay-desk-btn-danger' : 'pay-desk-btn-success'}`}
                  onClick={async () => {
                    const { type, id } = confirm;
                    setConfirm(null);
                    if (type === 'approve') await handleApprove(id);
                    else await handleDelete(id);
                  }}
                >
                  {confirm.type === 'delete' ? 'Yes, delete it' : 'Yes, approve it'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
