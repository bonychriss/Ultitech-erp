import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  CheckCircle2,
  Download,
  FileSpreadsheet,
  Inbox,
  Loader2,
  Mail,
  RotateCcw,
  Send,
  Trash2,
  Wallet,
  X,
} from 'lucide-react';
import {
  buildEditPayslipUrl,
  buildPayslipUrl,
  deskPageUrl,
  fetchRun,
  formatAmount,
  resolveRunId,
  runAction,
} from '../api/payrollDesk';
import EmployeeAvatar from '../components/EmployeeAvatar.jsx';
import editIcon from '../assets/edit-icon.png';

function isRowActionTarget(target) {
  if (!(target instanceof Element)) return false;
  return Boolean(target.closest('a, button, input, select, textarea, label, [data-pay-row-ignore]'));
}

export default function ViewRunPage() {
  const runId = resolveRunId();
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState(null);
  const [search, setSearch] = useState('');

  const loadData = useCallback(async () => {
    if (runId <= 0) {
      setError('Invalid payroll run id.');
      setLoading(false);
      return;
    }
    setLoading(true);
    setError('');
    try {
      const data = await fetchRun(runId);
      setInit(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load payroll run.');
    } finally {
      setLoading(false);
    }
  }, [runId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  useEffect(() => {
    if (!notice) return undefined;
    const timer = window.setTimeout(() => setNotice(''), 4500);
    return () => window.clearTimeout(timer);
  }, [notice]);

  const links = init?.links || {};
  const run = init?.run || {};
  const can = init?.can || {};
  const allSlips = init?.slips || [];
  const totals = init?.totals || {};

  const slips = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return allSlips;
    return allSlips.filter((slip) => (
      String(slip.fullName || '').toLowerCase().includes(q)
      || String(slip.department || '').toLowerCase().includes(q)
    ));
  }, [allSlips, search]);

  async function performAction(action, payslipId = 0) {
    setBusy(true);
    setError('');
    try {
      const res = await runAction({ id: runId, action, payslipId });
      const payload = res.data || {};
      if (payload.deleted && payload.redirect) {
        window.location.href = payload.redirect;
        return;
      }
      if (payload.data) {
        setInit(payload.data);
      } else {
        await loadData();
      }
      setNotice(res.message || payload.message || 'Updated.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Action failed.');
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  }

  function openPayslip(slipId) {
    window.open(buildPayslipUrl(slipId, links), '_blank', 'noopener,noreferrer');
  }

  if (loading && !init) {
    return (
      <div className="pay-desk-page pay-desk-boot-loading" role="status" aria-live="polite">
        <Loader2 className="pay-desk-boot-spinner" aria-hidden="true" />
        <span>Loading payroll run...</span>
      </div>
    );
  }

  if (!init && error) {
    return (
      <div className="pay-desk-page">
        <div className="pay-desk-flash-error" role="alert">{error}</div>
        <a href={deskPageUrl('index.php')} className="pay-desk-btn pay-desk-btn-secondary">
          Back to payroll
        </a>
      </div>
    );
  }

  return (
    <div className="pay-desk-page">
      <div className="pay-desk-page-header pay-desk-page-header--desk">
        <div className="pay-desk-page-header-search">
          <div className="pay-desk-search-field">
            <input
              type="search"
              className="pay-desk-search-input pay-desk-search-input--plain"
              placeholder="Search employees in this run..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              aria-label="Search payslips"
            />
          </div>
        </div>
        <div className="pay-desk-page-header-actions">
          {can.delete && (
            <button
              type="button"
              className="pay-desk-btn pay-desk-btn-danger pay-desk-btn--pill"
              disabled={busy}
              onClick={() => setConfirm({
                action: 'delete',
                title: 'Cancel this run?',
                body: 'All payslips for this draft run will be removed.',
              })}
            >
              <Trash2 size={14} aria-hidden="true" />
              Cancel run
            </button>
          )}
          {can.approve && (
            <button
              type="button"
              className="pay-desk-btn pay-desk-btn-primary pay-desk-btn--pill"
              disabled={busy}
              onClick={() => setConfirm({
                action: 'approve',
                title: 'Approve this payroll run?',
                body: 'This will finalize the figures for approval.',
              })}
            >
              <CheckCircle2 size={14} aria-hidden="true" />
              Approve run
            </button>
          )}
          {can.revert && (
            <button
              type="button"
              className="pay-desk-btn pay-desk-btn-secondary"
              disabled={busy}
              onClick={() => performAction('revert')}
            >
              <RotateCcw size={14} aria-hidden="true" />
              Revert to draft
            </button>
          )}
          {can.markPaid && (
            <button
              type="button"
              className="pay-desk-btn pay-desk-btn-success"
              disabled={busy}
              onClick={() => setConfirm({
                action: 'mark_paid',
                title: 'Mark as paid?',
                body: 'This will generate journal entries in accounting.',
              })}
            >
              <Wallet size={14} aria-hidden="true" />
              Mark as paid
            </button>
          )}
        </div>
      </div>

      {error && <div className="pay-desk-flash-error" role="alert">{error}</div>}
      {notice && (
        <div className="pay-desk-flash-ok" role="status">
          <span>{notice}</span>
          <button type="button" className="pay-desk-flash-dismiss" onClick={() => setNotice('')} aria-label="Dismiss">
            <X size={14} aria-hidden="true" />
          </button>
        </div>
      )}

      <section className="pay-desk-results">
        <div className="pay-desk-results-head pay-run-results-head">
          <span className="pay-desk-results-count">
            {slips.length} {slips.length === 1 ? 'payslip' : 'payslips'}
          </span>
          <div className="pay-run-toolbar">
            {can.sendAll && (
              <button
                type="button"
                className="pay-desk-btn pay-desk-btn-secondary"
                disabled={busy}
                onClick={() => setConfirm({
                  action: 'send_to_account',
                  title: 'Send payslips to accounts?',
                  body: 'This will make them visible to employees.',
                })}
              >
                <Send size={14} aria-hidden="true" />
                Send to account
              </button>
            )}
            {run.isPublished && (
              <span className="pay-desk-btn pay-desk-btn-secondary" style={{ opacity: 0.7, cursor: 'default' }}>
                <CheckCircle2 size={14} aria-hidden="true" />
                Sent to accounts
              </span>
            )}
            <a href={links.exportExcel || '#'} className="pay-desk-btn pay-desk-btn-secondary">
              <FileSpreadsheet size={14} aria-hidden="true" />
              Export Excel
            </a>
            <a href={links.emailAll || '#'} className="pay-desk-btn pay-desk-btn-secondary">
              <Mail size={14} aria-hidden="true" />
              Email all
            </a>
          </div>
        </div>

        {slips.length === 0 ? (
          <div className="pay-desk-empty">
            <Inbox className="pay-desk-empty-icon" aria-hidden="true" />
            <p className="pay-desk-empty-title">No payslips found</p>
            <p className="pay-desk-empty-sub">
              {search ? 'Try a different search.' : 'This run has no employee payslips.'}
            </p>
          </div>
        ) : (
          <div className="pay-desk-table-wrap">
            <table className="pay-desk-table pay-run-table">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th className="pay-desk-hide-lg">Department</th>
                  <th className="pay-desk-hide-lg">Basic</th>
                  <th className="pay-desk-hide-lg">Allowances</th>
                  <th className="pay-desk-hide-md">Gross</th>
                  <th className="pay-desk-hide-md">Tax</th>
                  <th className="pay-desk-hide-md">NSSF</th>
                  <th>Net</th>
                  <th style={{ textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {slips.map((slip) => (
                  <tr
                    key={slip.id}
                    className="pay-desk-row-clickable"
                    tabIndex={0}
                    onClick={(event) => {
                      if (isRowActionTarget(event.target)) return;
                      openPayslip(slip.id);
                    }}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        openPayslip(slip.id);
                      }
                    }}
                  >
                    <td>
                      <div className="pay-desk-employee-cell">
                        <EmployeeAvatar name={slip.fullName} id={slip.userId || slip.id} />
                        <div className="pay-desk-employee-meta">
                          <div className="pay-desk-cell-main">{slip.fullName}</div>
                        </div>
                      </div>
                    </td>
                    <td className="pay-desk-hide-lg">
                      <span className="pay-desk-dept">{String(slip.department || '-').toUpperCase()}</span>
                    </td>
                    <td className="pay-desk-hide-lg">{formatAmount(slip.basicSalary)}</td>
                    <td className="pay-desk-hide-lg">{formatAmount(slip.allowances)}</td>
                    <td className="pay-desk-hide-md">{formatAmount(slip.grossSalary)}</td>
                    <td className="pay-desk-hide-md pay-run-deduct">{formatAmount(slip.taxDeduction)}</td>
                    <td className="pay-desk-hide-md pay-run-deduct">{formatAmount(slip.nssfDeduction)}</td>
                    <td className="pay-desk-amt">{formatAmount(slip.netSalary)}</td>
                    <td style={{ textAlign: 'right' }} data-pay-row-ignore>
                      <div className="pay-desk-actions">
                        {can.editPayslip && (
                          <a
                            href={buildEditPayslipUrl(slip.id, links)}
                            className="pay-desk-icon-btn pay-desk-icon-btn--edit"
                            title="Edit payslip"
                          >
                            <img src={editIcon} alt="" className="pay-desk-edit-icon" aria-hidden="true" />
                          </a>
                        )}
                        {can.sendAll && !slip.isPublished && (
                          <button
                            type="button"
                            className="pay-desk-icon-btn pay-desk-icon-btn--approve"
                            title="Send to account"
                            disabled={busy}
                            onClick={() => setConfirm({
                              action: 'send_single_to_account',
                              payslipId: slip.id,
                              title: 'Send this payslip?',
                              body: 'Make this payslip visible to the employee.',
                            })}
                          >
                            <Send size={15} aria-hidden="true" />
                          </button>
                        )}
                        {slip.isPublished && (
                          <span className="pay-desk-icon-btn" title="Sent" style={{ cursor: 'default', color: '#059669' }}>
                            <CheckCircle2 size={15} aria-hidden="true" />
                          </span>
                        )}
                        <a
                          href={buildPayslipUrl(slip.id, links, { download: 1 })}
                          className="pay-desk-icon-btn pay-desk-icon-btn--approve"
                          title="Download PDF"
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          <Download size={15} aria-hidden="true" />
                        </a>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr>
                  <td colSpan={7} className="pay-run-total-label">Grand total net payout</td>
                  <td className="pay-desk-amt">{formatAmount(totals.net)}</td>
                  <td />
                </tr>
              </tfoot>
            </table>
          </div>
        )}
      </section>

      {confirm && (
        <div className="pay-desk-modal-backdrop" role="presentation" onClick={() => !busy && setConfirm(null)}>
          <div
            className="pay-desk-modal pay-desk-confirm-modal"
            role="dialog"
            aria-modal="true"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="pay-salary-edit-modal-head">
              <h2 className="pay-salary-edit-modal-title">{confirm.title}</h2>
              <button
                type="button"
                className="pay-salary-edit-modal-close"
                onClick={() => setConfirm(null)}
                aria-label="Close"
                disabled={busy}
              >
                <X size={18} aria-hidden="true" />
              </button>
            </div>
            <div className="pay-desk-confirm-body">
              <p>{confirm.body}</p>
              <div className="pay-desk-confirm-actions">
                <button
                  type="button"
                  className="pay-desk-btn pay-desk-btn-secondary"
                  onClick={() => setConfirm(null)}
                  disabled={busy}
                >
                  Cancel
                </button>
                <button
                  type="button"
                  className={`pay-desk-btn ${confirm.action === 'delete' ? 'pay-desk-btn-danger' : 'pay-desk-btn-success'}`}
                  disabled={busy}
                  onClick={() => performAction(confirm.action, confirm.payslipId || 0)}
                >
                  {busy ? 'Working...' : 'Confirm'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
