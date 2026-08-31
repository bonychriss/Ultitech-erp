import React, { useEffect, useMemo, useState } from 'react';
import { getGeoPosition, postAttendanceAction } from '../api';

function formatTimeHm(value) {
  if (!value) return '--:--';
  const str = String(value).trim();
  const timePart = str.includes(' ') ? str.split(' ').pop() : str.includes('T') ? str.split('T').pop() : str;
  const parts = String(timePart).split(':');
  if (parts.length >= 2) {
    return `${parts[0].padStart(2, '0')}:${parts[1].padStart(2, '0')}`;
  }
  return str;
}

function formatHistoryDate(value) {
  if (!value) return '-';
  const d = new Date(`${value}T12:00:00`);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
}

function formatDisplayTime(raw) {
  if (!raw) return '';
  const normalized = String(raw).includes('T') ? raw : `1970-01-01T${raw}`;
  const d = new Date(normalized);
  if (Number.isNaN(d.getTime())) return String(raw);
  return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

function useLiveClock(timeZone) {
  const [nowLabel, setNowLabel] = useState('');
  const [dateLabel, setDateLabel] = useState('');

  useEffect(() => {
    const tick = () => {
      const now = new Date();
      const timeFmt = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        hour12: false,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
      });
      const dateFmt = new Intl.DateTimeFormat('en-US', {
        timeZone,
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
      });
      const parts = timeFmt.formatToParts(now);
      let H = '00';
      let M = '00';
      let S = '00';
      parts.forEach((p) => {
        if (p.type === 'hour') H = String(parseInt(p.value, 10)).padStart(2, '0');
        if (p.type === 'minute') M = String(parseInt(p.value, 10)).padStart(2, '0');
        if (p.type === 'second') S = String(parseInt(p.value, 10)).padStart(2, '0');
      });
      setNowLabel(`${H}:${M}:${S}`);
      setDateLabel(dateFmt.format(now));
    };
    tick();
    const id = window.setInterval(tick, 1000);
    return () => window.clearInterval(id);
  }, [timeZone]);

  return { nowLabel, dateLabel };
}

function statusClass(status) {
  if (status === 'On Time') return 'att-status-ontime';
  if (status === 'Late') return 'att-status-late';
  return 'att-status-other';
}

export default function AttendanceDesk({ data }) {
  const [state, setState] = useState(() => ({
    todayRecord: data.todayRecord || null,
    history: data.history || [],
    stats: data.stats || {},
    pendingTasks: data.pendingTasks || [],
    isIpAllowed: Boolean(data.isIpAllowed),
    currentIp: data.currentIp || '',
    message: data.message || '',
    msgType: data.msgType || '',
    clockInSuccess: data.clockInSuccess || null,
    clockOutSuccess: data.clockOutSuccess || null,
  }));

  const [planOpen, setPlanOpen] = useState(false);
  const [reviewOpen, setReviewOpen] = useState(false);
  const [newTasks, setNewTasks] = useState(['']);
  const [completedIds, setCompletedIds] = useState(() => new Set());
  const [busy, setBusy] = useState(false);
  const [successDismissed, setSuccessDismissed] = useState(false);

  const links = data.links || {};
  const user = data.user || {};
  const timeZone = data.timeZone || 'Africa/Dar_es_Salaam';
  const dateSummary = data.dateSummary || '';
  const { nowLabel, dateLabel } = useLiveClock(timeZone);
  const wallpapers = data.wallpapers || {};
  const clockCardStyle = useMemo(() => {
    const sunrise = wallpapers.sunrise || '';
    const sunset = wallpapers.sunset || '';
    if (!sunrise && !sunset) return undefined;
    let hour = 12;
    try {
      const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        hour: '2-digit',
        hour12: false,
      }).formatToParts(new Date());
      const h = parts.find((p) => p.type === 'hour');
      hour = h ? parseInt(h.value, 10) : 12;
    } catch (_) {
      hour = new Date().getHours();
    }
    // After noon (12:00+) use sunset; morning uses sunrise.
    const url = hour >= 12 ? sunset || sunrise : sunrise || sunset;
    if (!url) return undefined;
    return {
      backgroundImage: `linear-gradient(160deg, rgba(15, 23, 42, 0.55) 0%, rgba(15, 23, 42, 0.28) 45%, rgba(28, 25, 23, 0.45) 100%), url('${url}')`,
      backgroundPosition: 'center center',
      backgroundSize: 'cover',
      backgroundRepeat: 'no-repeat',
      backgroundColor: '#1c1917',
    };
  }, [wallpapers.sunrise, wallpapers.sunset, timeZone, nowLabel]);

  const todayRecord = state.todayRecord;
  const isOut = todayRecord && todayRecord.time_out;
  const isIn = todayRecord && todayRecord.time_in && !todayRecord.time_out;
  const canClockIn = !todayRecord;
  const canClockOut = Boolean(isIn);

  useEffect(() => {
    if (!state.clockInSuccess && !state.clockOutSuccess) return undefined;
    const isMobile = window.matchMedia('(max-width: 768px)').matches;
    if (isMobile) document.body.classList.add('att-success-sheet-open');
    return () => document.body.classList.remove('att-success-sheet-open');
  }, [state.clockInSuccess, state.clockOutSuccess]);

  const statusPill = useMemo(() => {
    if (isOut) {
      return (
        <div className="v2-status-pill">
          <i className="fas fa-flag-checkered" /> OUT AT {formatTimeHm(todayRecord.time_out)}
        </div>
      );
    }
    if (isIn) {
      return (
        <div className="v2-status-pill">
          <i className="fas fa-check-circle" /> IN AT {formatTimeHm(todayRecord.time_in)}
        </div>
      );
    }
    return (
      <div className="v2-status-pill">
        <i className="fas fa-clock" /> STANDBY
      </div>
    );
  }, [isIn, isOut, todayRecord]);

  function openPlan() {
    setNewTasks(['']);
    setPlanOpen(true);
  }

  function openReview() {
    setCompletedIds(new Set());
    setReviewOpen(true);
  }

  function dismissSuccess() {
    setSuccessDismissed(true);
    document.body.classList.remove('att-success-sheet-open');
  }

  function applyPayload(payload, extras = {}) {
    if (!payload) return;
    setState((cur) => ({
      ...cur,
      todayRecord: payload.todayRecord ?? cur.todayRecord,
      history: payload.history ?? cur.history,
      stats: payload.stats ?? cur.stats,
      pendingTasks: payload.pendingTasks ?? cur.pendingTasks,
      isIpAllowed: payload.isIpAllowed ?? cur.isIpAllowed,
      currentIp: payload.currentIp ?? cur.currentIp,
      message: extras.message ?? '',
      msgType: extras.msgType ?? '',
      clockInSuccess: extras.clockInSuccess ?? null,
      clockOutSuccess: extras.clockOutSuccess ?? null,
    }));
    setSuccessDismissed(false);
  }

  async function submitClockIn() {
    const tasks = newTasks.map((t) => t.trim()).filter(Boolean);
    if (tasks.length === 0 && state.pendingTasks.length === 0) {
      window.alert('Please enter at least one task for today.');
      return;
    }
    setPlanOpen(false);
    setBusy(true);
    try {
      const geo = await getGeoPosition();
      const result = await postAttendanceAction({
        action: 'clock_in',
        latitude: geo.latitude,
        longitude: geo.longitude,
        new_tasks: tasks,
      });
      if (!result.success) {
        applyPayload(result.data, { message: result.message || 'Clock in failed.', msgType: 'danger' });
        return;
      }
      applyPayload(result.data, { clockInSuccess: result.clockInSuccess || null });
    } catch (err) {
      setState((cur) => ({
        ...cur,
        message: err instanceof Error ? err.message : 'Clock in failed.',
        msgType: 'danger',
      }));
    } finally {
      setBusy(false);
    }
  }

  async function submitClockOut() {
    setReviewOpen(false);
    setBusy(true);
    try {
      const geo = await getGeoPosition();
      const result = await postAttendanceAction({
        action: 'clock_out',
        latitude: geo.latitude,
        longitude: geo.longitude,
        completed_task_ids: Array.from(completedIds),
      });
      if (!result.success) {
        applyPayload(result.data, { message: result.message || 'Clock out failed.', msgType: 'danger' });
        return;
      }
      applyPayload(result.data, { clockOutSuccess: result.clockOutSuccess || null });
    } catch (err) {
      setState((cur) => ({
        ...cur,
        message: err instanceof Error ? err.message : 'Clock out failed.',
        msgType: 'danger',
      }));
    } finally {
      setBusy(false);
    }
  }

  const showInSuccess = state.clockInSuccess && !successDismissed;
  const showOutSuccess = state.clockOutSuccess && !successDismissed;
  const stats = state.stats || {};

  return (
    <div className="att-shell att-page-clock">
      <div className="att-main">
        <div className="att-top-bar">
          <div className="att-title-wrap">
            <h1>Attendance</h1>
            <p className="att-sub-info att-sub-info--inline">
              <i className="fas fa-calendar-alt att-me-2 att-text-slate" />
              <strong>{dateSummary}</strong>
              <span className="att-sub-sep">-</span>
              Clock in/out and review recent history
            </p>
          </div>
        </div>

        {showInSuccess ? (
          <ClockInSuccessOverlay data={state.clockInSuccess} onDismiss={dismissSuccess} />
        ) : null}
        {showOutSuccess ? (
          <ClockOutSuccessOverlay data={state.clockOutSuccess} onDismiss={dismissSuccess} />
        ) : null}

        <div className="att-grid">
          <div className="att-col-left">
            <div className="clock-card-v2" style={clockCardStyle}>
              <div className="v2-date">{dateLabel}</div>
              <div className="v2-time">{nowLabel || '--:--:--'}</div>
              {statusPill}

              {!state.isIpAllowed ? (
                <div className="att-alert">
                  <div className="att-alert-title">
                    <i className="fas fa-exclamation-triangle" /> Out of office network
                  </div>
                  <p className="att-m-0" style={{ opacity: 0.7 }}>
                    Your IP: <strong>{state.currentIp}</strong>
                    <br />
                    Connect to the office network or enable GPS location to clock in or out.
                  </p>
                </div>
              ) : null}

              <div className="att-mt-4">
                {canClockIn ? (
                  <button type="button" className="att-glass-btn" disabled={busy} onClick={openPlan}>
                    {busy ? 'Processing...' : 'Clock in Now'}
                  </button>
                ) : null}
                {canClockOut ? (
                  <button type="button" className="att-btn-clock-out" disabled={busy} onClick={openReview}>
                    {busy ? 'Processing...' : 'Clock out'}
                  </button>
                ) : null}
              </div>
            </div>

            {showInSuccess ? (
              <div className="att-clockin-success" role="status" aria-live="polite">
                <div className="att-clockin-success-head">
                  <div className="att-clockin-success-icon">
                    <i className="fas fa-check" />
                  </div>
                  <div>
                    <p className="att-clockin-success-title" style={{ margin: 0 }}>
                      You&apos;re in!
                    </p>
                    <p className="att-clockin-success-sub" style={{ margin: 0 }}>
                      {state.clockInSuccess.time_in_display}
                      {' - '}
                      {(state.clockInSuccess.new_tasks || 0) + (state.clockInSuccess.carried_over || 0)} task
                      {(state.clockInSuccess.new_tasks || 0) + (state.clockInSuccess.carried_over || 0) === 1
                        ? ''
                        : 's'}{' '}
                      planned today
                    </p>
                  </div>
                </div>
              </div>
            ) : null}

            {showOutSuccess ? (
              <div className="att-clockin-success att-clockout-success" role="status" aria-live="polite">
                <div className="att-clockin-success-head">
                  <div
                    className="att-clockin-success-icon"
                    style={{ background: '#f97316', boxShadow: '0 4px 12px rgba(249,115,22,0.4)' }}
                  >
                    <i className="fas fa-sign-out-alt" />
                  </div>
                  <div>
                    <p className="att-clockin-success-title" style={{ margin: 0, color: '#9a3412' }}>
                      You&apos;re out!
                    </p>
                    <p className="att-clockin-success-sub" style={{ margin: 0 }}>
                      {state.clockOutSuccess.time_out_display}
                      {' - '}
                      {Number(state.clockOutSuccess.total_hours || 0).toFixed(1)}h worked
                      {' - '}
                      {state.clockOutSuccess.completed || 0} task
                      {(state.clockOutSuccess.completed || 0) === 1 ? '' : 's'} done
                    </p>
                  </div>
                </div>
              </div>
            ) : null}

            <div className="sig-card">
              <div className="sig-label">Digital signature</div>
              <div className="sig-box">
                {user.signatureUrl ? (
                  <img src={user.signatureUrl} className="att-sig-img" alt="Signature" />
                ) : (
                  <span className="att-text-slate" style={{ fontSize: 12 }}>
                    No signature on file
                  </span>
                )}
              </div>
            </div>
          </div>

          <div className="att-col-right">
            {state.message ? (
              <div className={`att-alert-banner is-${state.msgType || 'danger'}`}>{state.message}</div>
            ) : null}

            <div className="att-kpi-grid" aria-label="Attendance summary">
              <div className="att-kpi-tile">
                <div className="att-kpi-icon att-kpi-icon--hours" aria-hidden="true">
                  <i className="fas fa-clock" />
                </div>
                <div className="att-kpi-body">
                  <div className="att-kpi-label">Hours</div>
                  <div className="att-kpi-value is-hours">{Number(stats.total_hours || 0).toFixed(1)}h</div>
                </div>
              </div>
              <div className="att-kpi-tile">
                <div className="att-kpi-icon att-kpi-icon--punctual" aria-hidden="true">
                  <i className="fas fa-user-check" />
                </div>
                <div className="att-kpi-body">
                  <div className="att-kpi-label">Punctual</div>
                  <div className="att-kpi-value is-punctual">
                    {Number(stats.on_time_days || 0)}/{Number(stats.total_days || 0)}
                  </div>
                </div>
              </div>
              <div className="att-kpi-tile">
                <div className="att-kpi-icon att-kpi-icon--ot" aria-hidden="true">
                  <i className="fas fa-business-time" />
                </div>
                <div className="att-kpi-body">
                  <div className="att-kpi-label">Overtime</div>
                  <div className="att-kpi-value is-ot">{Number(stats.total_ot || 0).toFixed(1)}h</div>
                </div>
              </div>
              <div className="att-kpi-tile">
                <div className="att-kpi-icon att-kpi-icon--late" aria-hidden="true">
                  <i className="fas fa-exclamation-circle" />
                </div>
                <div className="att-kpi-body">
                  <div className="att-kpi-label">Late</div>
                  <div className="att-kpi-value is-late">{Number(stats.late_days || 0)}</div>
                </div>
              </div>
            </div>

            <div className="activity-card">
              <div className="card-header">
                <h3 className="card-title">Recent activity</h3>
                <div className="att-text-slate" style={{ fontSize: 12, fontWeight: 700 }}>
                  Last 30 days
                </div>
              </div>
              <div className="table-responsive">
                <table className="table table-v3 mb-0">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Status</th>
                      <th>In</th>
                      <th>Out</th>
                      <th>Hours</th>
                      <th>OT</th>
                    </tr>
                  </thead>
                  <tbody>
                    {state.history.length === 0 ? (
                      <tr>
                        <td colSpan={6}>
                          <div className="att-empty-history">
                            <i className="fas fa-calendar-times" />
                            <span>No attendance records yet.</span>
                          </div>
                        </td>
                      </tr>
                    ) : (
                      state.history.map((row) => (
                        <tr key={`${row.date}-${row.time_in || ''}`}>
                          <td>
                            <strong>{formatHistoryDate(row.date)}</strong>
                          </td>
                          <td>
                            <span className={statusClass(row.status)}>{row.status}</span>
                          </td>
                          <td>{formatTimeHm(row.time_in)}</td>
                          <td>{row.time_out ? formatTimeHm(row.time_out) : '--:--'}</td>
                          <td>
                            <strong>{row.total_hours ?? '0'}</strong>h
                          </td>
                          <td>{row.overtime_hours ? `+${row.overtime_hours}` : '--'}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      {planOpen ? (
        <PlanModal
          pendingTasks={state.pendingTasks}
          newTasks={newTasks}
          setNewTasks={setNewTasks}
          onClose={() => setPlanOpen(false)}
          onConfirm={submitClockIn}
          busy={busy}
        />
      ) : null}

      {reviewOpen ? (
        <ReviewModal
          pendingTasks={state.pendingTasks}
          completedIds={completedIds}
          setCompletedIds={setCompletedIds}
          onClose={() => setReviewOpen(false)}
          onConfirm={submitClockOut}
          busy={busy}
        />
      ) : null}
    </div>
  );
}

function ClockInSuccessOverlay({ data, onDismiss }) {
  const ciNew = Number(data.new_tasks || 0);
  const ciCarried = Number(data.carried_over || 0);
  const ciTotal = ciNew + ciCarried;
  return (
    <div
      className="att-clockin-success-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="clockInSuccessTitle"
      onClick={(e) => {
        if (e.target === e.currentTarget) onDismiss();
      }}
    >
      <div className="att-clockin-success-banner" role="status" aria-live="polite" onClick={(e) => e.stopPropagation()}>
        <button type="button" className="att-clockin-success-dismiss" onClick={onDismiss} aria-label="Dismiss">
          <i className="fas fa-times" aria-hidden="true" />
        </button>
        <div className="att-clockin-success-head">
          <div className="att-clockin-success-icon" aria-hidden="true">
            <i className="fas fa-check" />
          </div>
          <div>
            <h2 id="clockInSuccessTitle" className="att-clockin-success-title">
              Clocked in successfully
            </h2>
            <p className="att-clockin-success-sub">
              You checked in at <strong>{data.time_in_display || formatDisplayTime(data.time_in)}</strong>
              {' - '}Status: <strong>{data.status || 'On Time'}</strong>
            </p>
          </div>
        </div>
        <div className="att-clockin-success-stats">
          <div className="att-clockin-success-stat">
            <strong>{ciNew}</strong> new task{ciNew === 1 ? '' : 's'} added
          </div>
          {ciCarried > 0 ? (
            <div className="att-clockin-success-stat">
              <strong>{ciCarried}</strong> carried over
            </div>
          ) : null}
          <div className="att-clockin-success-stat">
            <strong>{ciTotal}</strong> total for today
          </div>
        </div>
        {ciNew > 0 && Array.isArray(data.tasks) && data.tasks.length > 0 ? (
          <>
            <p className="att-success-note att-mb-1">Your new tasks</p>
            <ul className="att-clockin-success-tasks">
              {data.tasks.map((title, idx) => (
                <li key={`${title}-${idx}`}>
                  <span>{idx + 1}.</span>
                  <span>{title}</span>
                </li>
              ))}
            </ul>
          </>
        ) : null}
        {ciNew === 0 && ciCarried > 0 ? (
          <p className="att-success-body-note att-mb-0">
            No new tasks added - continuing with {ciCarried} carried-over task{ciCarried === 1 ? '' : 's'}.
          </p>
        ) : null}
      </div>
    </div>
  );
}

function ClockOutSuccessOverlay({ data, onDismiss }) {
  const coCompleted = Number(data.completed || 0);
  const coCarried = Number(data.carried_over || 0);
  const coTotal = coCompleted + coCarried;
  const coOvertime = Number(data.overtime_hours || 0);
  return (
    <div
      className="att-clockin-success-overlay att-clockout-success-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="clockOutSuccessTitle"
      onClick={(e) => {
        if (e.target === e.currentTarget) onDismiss();
      }}
    >
      <div
        className="att-clockin-success-banner att-clockout-success-banner"
        role="status"
        aria-live="polite"
        onClick={(e) => e.stopPropagation()}
      >
        <button type="button" className="att-clockin-success-dismiss" onClick={onDismiss} aria-label="Dismiss">
          <i className="fas fa-times" aria-hidden="true" />
        </button>
        <div className="att-clockin-success-head">
          <div className="att-clockin-success-icon" aria-hidden="true">
            <i className="fas fa-sign-out-alt" />
          </div>
          <div>
            <h2 id="clockOutSuccessTitle" className="att-clockin-success-title">
              Clocked out successfully
            </h2>
            <p className="att-clockin-success-sub">
              You checked out at <strong>{data.time_out_display}</strong>
              {data.time_in_display ? (
                <>
                  {' '}
                  - In at <strong>{data.time_in_display}</strong>
                </>
              ) : null}
              {' - '}
              <strong>{Number(data.total_hours || 0).toFixed(1)}h</strong> worked
            </p>
          </div>
        </div>
        <div className="att-clockin-success-stats">
          <div className="att-clockin-success-stat">
            <strong>{coCompleted}</strong> task{coCompleted === 1 ? '' : 's'} completed
          </div>
          {coCarried > 0 ? (
            <div className="att-clockin-success-stat">
              <strong>{coCarried}</strong> carrying over
            </div>
          ) : null}
          {coOvertime > 0 ? (
            <div className="att-clockin-success-stat">
              <strong>{coOvertime.toFixed(1)}h</strong> overtime
            </div>
          ) : coCarried === 0 ? (
            <div className="att-clockin-success-stat">
              <strong>{coTotal}</strong> tasks reviewed
            </div>
          ) : null}
        </div>
        {coCompleted > 0 && Array.isArray(data.completed_tasks) && data.completed_tasks.length > 0 ? (
          <>
            <p className="att-success-note att-mb-1">Completed today</p>
            <ul className="att-clockin-success-tasks">
              {data.completed_tasks.map((title, idx) => (
                <li key={`c-${title}-${idx}`}>
                  <span>{idx + 1}.</span>
                  <span>{title}</span>
                </li>
              ))}
            </ul>
          </>
        ) : null}
        {coCarried > 0 && Array.isArray(data.carried_tasks) && data.carried_tasks.length > 0 ? (
          <>
            <p className="att-success-note att-mb-1 att-mt-2">Carrying over tomorrow</p>
            <ul className="att-clockin-success-tasks">
              {data.carried_tasks.map((title, idx) => (
                <li key={`o-${title}-${idx}`}>
                  <span>{idx + 1}.</span>
                  <span>{title}</span>
                </li>
              ))}
            </ul>
          </>
        ) : null}
        {coTotal === 0 ? (
          <p className="att-success-body-note att-mb-0">No pending tasks for today. Have a great evening!</p>
        ) : null}
      </div>
    </div>
  );
}

function PlanModal({ pendingTasks, newTasks, setNewTasks, onClose, onConfirm, busy }) {
  return (
    <div
      className="att-plan-overlay is-open"
      aria-hidden="false"
      role="dialog"
      aria-labelledby="clockInModalTitle"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="att-plan-modal" role="document" onClick={(e) => e.stopPropagation()}>
        <button type="button" className="att-plan-close" onClick={onClose} aria-label="Close">
          <i className="fas fa-times" aria-hidden="true" />
        </button>
        <div className="att-plan-header">
          <div className="att-plan-header-icon" aria-hidden="true">
            <i className="fas fa-list-ul" />
          </div>
          <h2 id="clockInModalTitle" className="att-plan-title">
            Plan Your Day
          </h2>
        </div>
        <div className="att-plan-body">
          {pendingTasks.length > 0 ? (
            <div className="att-plan-carried">
              <p className="att-plan-carried-label">Carried over tasks</p>
              <ul className="att-plan-carried-list">
                {pendingTasks.map((task) => (
                  <li key={task.id}>
                    <i className="fas fa-arrow-right" aria-hidden="true" />
                    <span>
                      {task.task_description}
                      <small>Added: {task.task_date}</small>
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          <p className="att-plan-label">New tasks for today</p>
          <div className="att-plan-tasks">
            {newTasks.map((value, index) => (
              <div className="att-plan-task-row" key={`task-${index}`}>
                <span className="att-plan-task-num" aria-hidden="true">
                  {index + 1}
                </span>
                <input
                  type="text"
                  className="new-task-input"
                  placeholder="e.g. Prepare monthly report..."
                  value={value}
                  onChange={(e) => {
                    const next = [...newTasks];
                    next[index] = e.target.value;
                    setNewTasks(next);
                  }}
                />
              </div>
            ))}
          </div>
          <button
            type="button"
            className="att-plan-add-task"
            onClick={() => setNewTasks((cur) => [...cur, ''])}
          >
            <span className="att-plan-add-icon" aria-hidden="true">
              <i className="fas fa-plus" />
            </span>
            Add another task
          </button>
        </div>
        <div className="att-plan-footer">
          <button type="button" className="att-plan-btn-cancel" onClick={onClose}>
            Cancel
          </button>
          <button type="button" className="att-plan-btn-confirm" disabled={busy} onClick={onConfirm}>
            <i className="fas fa-check" aria-hidden="true" /> Confirm &amp; Clock In
          </button>
        </div>
      </div>
    </div>
  );
}

function ReviewModal({ pendingTasks, completedIds, setCompletedIds, onClose, onConfirm, busy }) {
  return (
    <div
      className="att-review-overlay is-open"
      aria-hidden="false"
      role="dialog"
      aria-labelledby="clockOutModalTitle"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="att-review-modal" role="document" onClick={(e) => e.stopPropagation()}>
        <button type="button" className="att-review-close" onClick={onClose} aria-label="Close">
          <i className="fas fa-times" aria-hidden="true" />
        </button>
        <div className="att-review-header">
          <div className="att-review-header-icon" aria-hidden="true">
            <i className="fas fa-check" />
          </div>
          <h2 id="clockOutModalTitle" className="att-review-title">
            Review Your Day
          </h2>
        </div>
        <p className="att-review-desc">
          Check off the tasks you completed today. Unchecked tasks will automatically carry over to tomorrow.
        </p>
        <div className="att-review-body">
          {pendingTasks.length > 0 ? (
            <div className="att-review-tasks">
              {pendingTasks.map((task) => {
                const id = Number(task.id);
                const checked = completedIds.has(id);
                return (
                  <label className="att-review-task" key={id}>
                    <input
                      type="checkbox"
                      className="completed-task-checkbox"
                      checked={checked}
                      onChange={(e) => {
                        setCompletedIds((cur) => {
                          const next = new Set(cur);
                          if (e.target.checked) next.add(id);
                          else next.delete(id);
                          return next;
                        });
                      }}
                      value={id}
                    />
                    <span>{task.task_description}</span>
                  </label>
                );
              })}
            </div>
          ) : (
            <div className="att-review-empty">
              <div className="att-review-empty-art" aria-hidden="true">
                <span className="att-review-empty-spark att-review-empty-spark--1">+</span>
                <span className="att-review-empty-spark att-review-empty-spark--2">+</span>
                <span className="att-review-empty-dot att-review-empty-dot--1" />
                <span className="att-review-empty-dot att-review-empty-dot--2" />
                <div className="att-review-empty-circle">
                  <div className="att-review-empty-list">
                    <div className="att-review-empty-row">
                      <span className="att-review-empty-mark done">
                        <i className="fas fa-check" />
                      </span>
                      <span className="att-review-empty-line" />
                    </div>
                    <div className="att-review-empty-row">
                      <span className="att-review-empty-mark done">
                        <i className="fas fa-check" />
                      </span>
                      <span className="att-review-empty-line" />
                    </div>
                    <div className="att-review-empty-row">
                      <span className="att-review-empty-mark pending" />
                      <span className="att-review-empty-line" />
                    </div>
                  </div>
                </div>
              </div>
              <p className="att-review-empty-title">No pending tasks for today.</p>
              <p className="att-review-empty-sub">Great work! Enjoy the rest of your day.</p>
            </div>
          )}
        </div>
        <div className="att-review-footer">
          <button type="button" className="att-review-btn-cancel" onClick={onClose}>
            Cancel
          </button>
          <button type="button" className="att-review-btn-confirm" disabled={busy} onClick={onConfirm}>
            <i className="far fa-clock" aria-hidden="true" /> Confirm &amp; Clock Out
          </button>
        </div>
      </div>
    </div>
  );
}
