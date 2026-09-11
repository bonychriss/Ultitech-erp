import { useCallback, useEffect, useMemo, useState } from 'react';
import { DotLottieReact } from '@lottiefiles/dotlottie-react';
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
  fetchEmailJobStatus,
  fetchRun,
  formatAmount,
  resolveRunId,
  runAction,
  startEmailJob,
  waitForEmailJob,
} from '../api/payrollDesk';
import EmployeeAvatar from '../components/EmployeeAvatar.jsx';
import EditPayslipModal from '../components/EditPayslipModal.jsx';
import editIcon from '../assets/edit-icon.png';
import paperPlaneLottieUrl from '../assets/paper-plane.lottie?url';

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
  const [sendingLabel, setSendingLabel] = useState('Sending emails…');
  const [confirm, setConfirm] = useState(null);
  const [selectedRecipientIds, setSelectedRecipientIds] = useState([]);
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
  const mail = init?.mail || {};
  const mailEnabled = Boolean(mail.enabled);
  const allSlips = init?.slips || [];
  const totals = init?.totals || {};

  const slips = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return allSlips;
    return allSlips.filter((slip) => (
      String(slip.fullName || '').toLowerCase().includes(q)
      || String(slip.department || '').toLowerCase().includes(q)
      || String(slip.email || '').toLowerCase().includes(q)
    ));
  }, [allSlips, search]);

  const emailRecipients = useMemo(() => (
    allSlips
      .filter((slip) => String(slip.email || '').trim() !== '')
      .map((slip) => ({
        id: slip.id,
        fullName: slip.fullName || 'Employee',
        email: String(slip.email || '').trim(),
        department: slip.department || '',
      }))
  ), [allSlips]);

  const noEmailSlips = useMemo(() => (
    allSlips.filter((slip) => String(slip.email || '').trim() === '')
  ), [allSlips]);

  function openSendAllConfirm() {
    setConfirm({
      action: 'send_to_account',
      title: 'Send payslips to accounts?',
      body: 'This will make them visible to employees in their accounts.',
    });
  }

  function openEmailConfirm() {
    if (!mailEnabled) {
      if (links.emailSettings) {
        window.location.href = links.emailSettings;
      } else {
        setError('Enable Payroll under Email settings first.');
      }
      return;
    }
    const recipients = emailRecipients;
    setSelectedRecipientIds(recipients.map((person) => person.id).filter(Boolean));
    const fromLabel = mail.fromName && mail.fromEmail
      ? `${mail.fromName} <${mail.fromEmail}>`
      : (mail.fromEmail || 'the system mailbox');
    setConfirm({
      action: 'email_selected',
      title: 'Send via email?',
      body: `Select employees to email from ${fromLabel}.`,
      recipients,
      skipped: noEmailSlips.map((slip) => slip.fullName || 'Employee'),
      selectable: true,
    });
  }

  function openSendSingleConfirm(slip) {
    setConfirm({
      action: 'send_single_to_account',
      payslipId: slip.id,
      title: 'Send this payslip?',
      body: 'Make this payslip visible to the employee account.',
    });
  }

  function openEmailSingleConfirm(slip) {
    if (!mailEnabled) {
      if (links.emailSettings) {
        window.location.href = links.emailSettings;
      } else {
        setError('Enable Payroll under Email settings first.');
      }
      return;
    }
    const email = String(slip.email || '').trim();
    const recipients = email
      ? [{ id: slip.id, fullName: slip.fullName || 'Employee', email, department: slip.department || '' }]
      : [];
    setSelectedRecipientIds(recipients.map((person) => person.id).filter(Boolean));
    const fromLabel = mail.fromName && mail.fromEmail
      ? `${mail.fromName} <${mail.fromEmail}>`
      : (mail.fromEmail || 'the system mailbox');
    setConfirm({
      action: 'email_selected',
      payslipId: slip.id,
      title: 'Send via email?',
      body: email
        ? `Email this payslip from ${fromLabel}.`
        : 'This employee has no email address.',
      recipients,
      skipped: email ? [] : [slip.fullName || 'Employee'],
      selectable: Boolean(email),
    });
  }

  async function performAction(action, payslipId = 0, payslipIds = null) {
    setBusy(true);
    setError('');
    if (action === 'email_selected') {
      setSendingLabel(
        Array.isArray(payslipIds) && payslipIds.length > 1
          ? `Sending ${payslipIds.length} payslip emails…`
          : 'Sending payslip email…',
      );
    }
    try {
      const payload = { id: runId, action, payslipId };
      if (Array.isArray(payslipIds)) {
        payload.payslipIds = payslipIds;
      }
      const res = await runAction(payload);
      const next = res.data || {};

      const jobId = String(next.emailJobId || res.emailJobId || '').trim();
      if (action === 'email_selected' && jobId) {
        // Server already spawned the worker; this is a same-browser backup.
        // Start polling immediately so labels update even if startEmailJob is slow.
        const pollPromise = waitForEmailJob(jobId, {
          onProgress: (status) => {
            if (status?.message) {
              setSendingLabel(String(status.message));
            }
          },
        });
        try {
          await startEmailJob(jobId);
        } catch (startErr) {
          // Ignore if server-side spawn already claimed the job / is running.
          const status = await fetchEmailJobStatus(jobId).catch(() => null);
          if (!status || String(status.status) === 'queued') {
            throw startErr;
          }
        }
        const job = await pollPromise;
        if (String(job.status) === 'failed') {
          throw new Error(job.error || job.message || 'Email send failed.');
        }
        if (next.data) {
          setInit(next.data);
        } else {
          await loadData();
        }
        setNotice(job.message || 'Payslip email sent.');
        return;
      }

      if (next.deleted && next.redirect) {
        window.location.href = next.redirect;
        return;
      }
      if (next.data) {
        setInit(next.data);
      } else {
        await loadData();
      }
      setNotice(res.message || next.message || 'Updated.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Action failed.');
    } finally {
      setBusy(false);
      setSendingLabel('Sending emails…');
      setConfirm(null);
      setSelectedRecipientIds([]);
    }
  }

  function openPayslip(slipId) {
    window.open(buildPayslipUrl(slipId, links), '_blank', 'noopener,noreferrer');
  }

  function toggleRecipient(id) {
    setSelectedRecipientIds((prev) => (
      prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]
    ));
  }

  function selectAllRecipients() {
    const ids = (confirm?.recipients || []).map((person) => person.id).filter(Boolean);
    setSelectedRecipientIds(ids);
  }

  function clearRecipients() {
    setSelectedRecipientIds([]);
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
                onClick={openSendAllConfirm}
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
            {can.email && (
              <button
                type="button"
                className="pay-desk-btn pay-desk-btn-secondary"
                disabled={busy}
                onClick={openEmailConfirm}
              >
                <Mail size={14} aria-hidden="true" />
                Send via email
              </button>
            )}
            <a href={links.exportExcel || '#'} className="pay-desk-btn pay-desk-btn-secondary">
              <FileSpreadsheet size={14} aria-hidden="true" />
              Export Excel
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
                            onClick={() => openSendSingleConfirm(slip)}
                          >
                            <Send size={15} aria-hidden="true" />
                          </button>
                        )}
                        {can.email && (
                          <button
                            type="button"
                            className="pay-desk-icon-btn pay-desk-icon-btn--approve"
                            title="Send via email"
                            disabled={busy}
                            onClick={() => openEmailSingleConfirm(slip)}
                          >
                            <Mail size={15} aria-hidden="true" />
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
            className={`pay-desk-modal pay-desk-confirm-modal${Array.isArray(confirm.recipients) ? ' pay-desk-confirm-modal--recipients' : ''}`}
            role="dialog"
            aria-modal="true"
            aria-busy={busy && confirm.action === 'email_selected' ? 'true' : undefined}
            onClick={(event) => event.stopPropagation()}
          >
            {busy && confirm.action === 'email_selected' ? (
              <div className="pay-desk-sending-overlay" role="status" aria-live="polite">
                <div className="pay-desk-sending-lottie" aria-hidden="true">
                  <DotLottieReact
                    src={paperPlaneLottieUrl}
                    autoplay
                    loop
                    style={{ width: '180px', height: '180px' }}
                  />
                </div>
                <p className="pay-desk-sending-title">{sendingLabel}</p>
                <p className="pay-desk-sending-sub">
                  Please wait while payslips are delivered
                  {confirm.selectable ? ` (${selectedRecipientIds.length})` : ''}.
                </p>
              </div>
            ) : null}
            <div className="pay-salary-edit-modal-head">
              <h2 className="pay-salary-edit-modal-title">{confirm.title}</h2>
              <button
                type="button"
                className="pay-salary-edit-modal-close"
                onClick={() => {
                  if (busy) return;
                  setConfirm(null);
                  setSelectedRecipientIds([]);
                }}
                aria-label="Close"
                disabled={busy}
              >
                <X size={18} aria-hidden="true" />
              </button>
            </div>
            <div className="pay-desk-confirm-body">
              <p>{confirm.body}</p>
              {Array.isArray(confirm.recipients) ? (
                <div className="pay-desk-recipient-box">
                  <div className="pay-desk-recipient-head">
                    <span>
                      Email recipients ({confirm.selectable ? selectedRecipientIds.length : confirm.recipients.length}
                      {confirm.selectable ? ` of ${confirm.recipients.length}` : ''})
                    </span>
                    {confirm.selectable && confirm.recipients.length > 0 ? (
                      <span className="pay-desk-recipient-tools">
                        <button type="button" className="pay-desk-recipient-tool" onClick={selectAllRecipients} disabled={busy}>
                          Select all
                        </button>
                        <button type="button" className="pay-desk-recipient-tool" onClick={clearRecipients} disabled={busy}>
                          Clear
                        </button>
                      </span>
                    ) : null}
                  </div>
                  {confirm.recipients.length > 0 ? (
                    <ul className="pay-desk-recipient-list">
                      {confirm.recipients.map((person) => {
                        const checked = selectedRecipientIds.includes(person.id);
                        return (
                          <li key={person.id || person.email}>
                            {confirm.selectable ? (
                              <label className="pay-desk-recipient-item">
                                <input
                                  type="checkbox"
                                  checked={checked}
                                  disabled={busy}
                                  onChange={() => toggleRecipient(person.id)}
                                />
                                <span className="pay-desk-recipient-meta">
                                  <span className="pay-desk-recipient-name">{person.fullName}</span>
                                  <span className="pay-desk-recipient-email">{person.email}</span>
                                </span>
                              </label>
                            ) : (
                              <>
                                <span className="pay-desk-recipient-name">{person.fullName}</span>
                                <span className="pay-desk-recipient-email">{person.email}</span>
                              </>
                            )}
                          </li>
                        );
                      })}
                    </ul>
                  ) : (
                    <p className="pay-desk-recipient-empty">No employees with an email address.</p>
                  )}
                  {Array.isArray(confirm.skipped) && confirm.skipped.length > 0 ? (
                    <p className="pay-desk-recipient-skip">
                      Skipped (no email): {confirm.skipped.join(', ')}
                    </p>
                  ) : null}
                </div>
              ) : null}
              <div className="pay-desk-confirm-actions">
                <button
                  type="button"
                  className="pay-desk-btn pay-desk-btn-secondary"
                  onClick={() => {
                    setConfirm(null);
                    setSelectedRecipientIds([]);
                  }}
                  disabled={busy}
                >
                  Cancel
                </button>
                <button
                  type="button"
                  className={`pay-desk-btn ${confirm.action === 'delete' || confirm.action === 'remove_payslip' ? 'pay-desk-btn-danger' : 'pay-desk-btn-success'} pay-desk-btn--pill`}
                  disabled={busy}
                  onClick={() => {
                    if (confirm.action === 'email_selected' && confirm.selectable && selectedRecipientIds.length === 0) {
                      setError('Select at least one employee to email.');
                      return;
                    }
                    performAction(
                      confirm.action,
                      confirm.payslipId || 0,
                      confirm.selectable ? selectedRecipientIds : null,
                    );
                  }}
                >
                  {busy
                    ? (confirm.action === 'email_selected' ? 'Sending…' : 'Working...')
                    : (confirm.action === 'remove_payslip'
                      ? 'Remove'
                      : (confirm.action === 'email_selected'
                        ? `Send email${confirm.selectable ? ` (${selectedRecipientIds.length})` : ''}`
                        : 'Confirm'))}
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
