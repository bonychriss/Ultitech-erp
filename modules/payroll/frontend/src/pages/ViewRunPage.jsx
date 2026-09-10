import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  CheckCircle2,
  Download,
  FileSpreadsheet,
  Inbox,
  Loader2,
  Mail,
  Send,
  Trash2,
  Wallet,
  X,
} from 'lucide-react';
import {
  buildPayslipUrl,
  deskPageUrl,
  fetchRun,
  formatAmount,
  resolveRunId,
  runAction,
} from '../api/payrollDesk';
import EmployeeAvatar from '../components/EmployeeAvatar.jsx';
import EditPayslipModal from '../components/EditPayslipModal.jsx';
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
  const [editPayslipId, setEditPayslipId] = useState(0);

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
          {can.markPaid && (
            <button
              type="button"
              className="pay-desk-btn pay-desk-btn-success pay-desk-btn--pill"
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
          <div className="pay-desk-table-wrap pay-run-register-wrap">
            <table className="pay-desk-table pay-run-table pay-run-register">
              <thead>
                <tr>
                  <th scope="col">SN</th>
                  <th scope="col">Name of the employee</th>
                  <th scope="col">Department</th>
                  <th scope="col">Basic salaries</th>
                  <th scope="col">Overtime &amp; allowances</th>
                  <th scope="col">Bonus / commission</th>
                  <th scope="col">Gross salaries</th>
                  <th scope="col">Employee NSSF 10%</th>
                  <th scope="col">Taxable salary</th>
                  <th scope="col">PAYE</th>
                  <th scope="col">Total deductions</th>
                  <th scope="col">Net salaries</th>
                  <th scope="col">Employer NSSF 10%</th>
                  <th scope="col">SDL 3.5%</th>
                  <th scope="col">WCF 0.5%</th>
                  <th scope="col">Employer cost</th>
                  <th scope="col" style={{ textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {slips.map((slip, index) => {
                  const basic = Number(slip.basicSalary) || 0;
                  const overtimeAllowances = Number(slip.allowances) || 0;
                  const bonus = Number(slip.bonusCommission) || 0;
                  const adjustment = Number(slip.monthlyAdjustment) || 0;
                  const gross = Number(slip.grossSalary) || (basic + overtimeAllowances + bonus + adjustment);
                  const nssf = Number(slip.nssfDeduction) || 0;
                  const taxable = Number(slip.taxableSalary) || Math.max(0, gross - nssf);
                  const paye = Number(slip.taxDeduction) || 0;
                  const other = Number(slip.otherDeductions) || 0;
                  const totalDeductions = nssf + paye + other;
                  const net = Number(slip.netSalary) || (gross - totalDeductions);
                  const employerNssf = Number(slip.employerNssf) || 0;
                  const sdl = Number(slip.sdlAmount) || 0;
                  const wcf = Number(slip.wcfAmount) || 0;
                  const employerCost = Number(slip.employerCost) || (gross + employerNssf + sdl + wcf);

                  return (
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
                    <td className="pay-run-sn">{index + 1}</td>
                    <td>
                      <div className="pay-desk-employee-cell">
                        <EmployeeAvatar name={slip.fullName} id={slip.userId || slip.id} />
                        <div className="pay-desk-employee-meta">
                          <div className="pay-desk-cell-main">{slip.fullName}</div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <span className="pay-desk-dept">{String(slip.department || '-').toUpperCase()}</span>
                    </td>
                    <td>{formatAmount(basic)}</td>
                    <td>{formatAmount(overtimeAllowances)}</td>
                    <td>{formatAmount(bonus)}</td>
                    <td>{formatAmount(gross)}</td>
                    <td className="pay-run-deduct">{formatAmount(nssf)}</td>
                    <td>{formatAmount(taxable)}</td>
                    <td className="pay-run-deduct">{formatAmount(paye)}</td>
                    <td className="pay-run-deduct">{formatAmount(totalDeductions)}</td>
                    <td className="pay-desk-amt">{formatAmount(net)}</td>
                    <td>{formatAmount(employerNssf)}</td>
                    <td>{formatAmount(sdl)}</td>
                    <td>{formatAmount(wcf)}</td>
                    <td className="pay-desk-amt">{formatAmount(employerCost)}</td>
                    <td style={{ textAlign: 'right' }} data-pay-row-ignore>
                      <div className="pay-desk-actions">
                        {can.editPayslip && (
                          <button
                            type="button"
                            className="pay-desk-icon-btn pay-desk-icon-btn--edit"
                            title="Edit payslip"
                            onClick={() => setEditPayslipId(Number(slip.id) || 0)}
                          >
                            <img src={editIcon} alt="" className="pay-desk-edit-icon" aria-hidden="true" />
                          </button>
                        )}
                        {can.removePayslip && (
                          <button
                            type="button"
                            className="pay-desk-icon-btn pay-desk-icon-btn--del"
                            title="Remove from run"
                            disabled={busy}
                            onClick={() => setConfirm({
                              action: 'remove_payslip',
                              payslipId: slip.id,
                              title: 'Remove employee from this run?',
                              body: `${slip.fullName || 'This employee'} will be removed from the draft payroll run. You can run payroll again later to include them.`,
                            })}
                          >
                            <Trash2 size={15} aria-hidden="true" />
                          </button>
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
                  );
                })}
              </tbody>
              <tfoot>
                <tr>
                  <td colSpan={3} className="pay-run-total-label">Grand totals</td>
                  <td>{formatAmount(totals.basic)}</td>
                  <td>{formatAmount(totals.allowances)}</td>
                  <td>{formatAmount(totals.bonus)}</td>
                  <td>{formatAmount(totals.gross)}</td>
                  <td className="pay-run-deduct">{formatAmount(totals.nssf)}</td>
                  <td>{formatAmount(totals.taxable)}</td>
                  <td className="pay-run-deduct">{formatAmount(totals.tax)}</td>
                  <td className="pay-run-deduct">
                    {formatAmount(
                      (Number(totals.nssf) || 0)
                      + (Number(totals.tax) || 0)
                      + (Number(totals.other) || 0),
                    )}
                  </td>
                  <td className="pay-desk-amt">{formatAmount(totals.net)}</td>
                  <td>{formatAmount(totals.employerNssf)}</td>
                  <td>{formatAmount(totals.sdl)}</td>
                  <td>{formatAmount(totals.wcf)}</td>
                  <td className="pay-desk-amt">{formatAmount(totals.employerCost)}</td>
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
                  className={`pay-desk-btn ${confirm.action === 'delete' || confirm.action === 'remove_payslip' ? 'pay-desk-btn-danger' : 'pay-desk-btn-success'} pay-desk-btn--pill`}
                  disabled={busy}
                  onClick={() => performAction(confirm.action, confirm.payslipId || 0)}
                >
                  {busy ? 'Working...' : (confirm.action === 'remove_payslip' ? 'Remove' : 'Confirm')}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      <EditPayslipModal
        open={editPayslipId > 0}
        payslipId={editPayslipId}
        onClose={() => setEditPayslipId(0)}
        onSaved={() => {
          loadData();
        }}
      />
    </div>
  );
}
