import React, { useEffect, useMemo, useRef, useState } from 'react';
import { postAttendanceAction } from '../api';
import '../attendance-records.css';
import '../attendance-analytics.css';

function formatDate(value) {
  if (!value) return '-';
  const d = new Date(`${value}T12:00:00`);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatTime(value) {
  if (!value) return null;
  const str = String(value).trim();
  const timePart = str.includes(' ') ? str.split(' ').pop() : str.includes('T') ? str.split('T').pop() : str;
  const parts = String(timePart).split(':');
  if (parts.length >= 2) return `${parts[0].padStart(2, '0')}:${parts[1].padStart(2, '0')}`;
  return str;
}

function statusClass(status) {
  const s = String(status || '').toLowerCase();
  if (s.includes('late')) return 'att-desk-status--late';
  if (s.includes('early')) return 'att-desk-status--early';
  return 'att-desk-status--on-time';
}

function BarChart({ labels = [], values = [], color = '#0284c7' }) {
  const max = Math.max(...values.map((v) => Number(v) || 0), 1);
  if (!labels.length) {
    return <div className="att-analytics-chart-empty">No data for this period.</div>;
  }
  return (
    <div className="att-analytics-bars" role="img" aria-label="Hours chart">
      {labels.map((label, idx) => {
        const value = Number(values[idx] || 0);
        const height = Math.max(4, Math.round((value / max) * 100));
        const shortLabel =
          String(label).length > 6
            ? new Date(`${label}T12:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
            : label;
        return (
          <div className="att-analytics-bar-col" key={`${label}-${idx}`}>
            <div className="att-analytics-bar-value">{value ? value.toFixed(1) : ''}</div>
            <div className="att-analytics-bar-track">
              <div
                className="att-analytics-bar-fill"
                style={{ height: `${height}%`, background: color }}
                title={`${label}: ${value}h`}
              />
            </div>
            <div className="att-analytics-bar-label">{shortLabel}</div>
          </div>
        );
      })}
    </div>
  );
}

function initialsFromName(name) {
  const parts = String(name || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (!parts.length) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
}

function aggregateEmployeeTotals(series = [], labels = [], grain = 'monthly') {
  const n = labels.length;
  if (!n) return [];

  let startIdx = 0;
  let endIdx = n - 1;
  if (grain === 'daily') {
    startIdx = endIdx;
  } else if (grain === 'weekly') {
    startIdx = Math.max(0, n - 7);
  }

  return series.map((s) => {
    const values = s.values || [];
    let total = 0;
    for (let i = startIdx; i <= endIdx; i += 1) {
      total += Number(values[i] || 0);
    }
    return {
      id: s.id,
      name: s.name,
      color: s.color || '#3b82f6',
      hours: Math.round(total * 10) / 10,
      initials: initialsFromName(s.name),
      punctuality: s.punctuality != null ? Number(s.punctuality) : null,
      signInScore: s.signInScore != null ? Number(s.signInScore) : null,
      signOutScore: s.signOutScore != null ? Number(s.signOutScore) : null,
      lateIns: Number(s.lateIns || 0),
      missedOuts: Number(s.missedOuts || 0),
      kpiPoints: s.kpiPoints != null ? Number(s.kpiPoints) : 0,
      kpiBreakdown: s.kpiBreakdown || null,
    };
  });
}

function EmployeeHoursBarChart({ employees = [], periodLabel = '', metric = 'hours' }) {
  const width = 760;
  const labelSpace = 118;
  const height = 280 + labelSpace;
  const pad = { top: 28, right: 16, bottom: labelSpace, left: 40 };
  const plotW = width - pad.left - pad.right;
  const plotH = height - pad.top - pad.bottom;
  const count = employees.length;
  const showPoints = metric === 'points';

  if (!count) {
    return <div className="att-analytics-chart-empty">No team members to chart.</div>;
  }

  const valueOf = (emp) => (showPoints ? Number(emp.kpiPoints || 0) : Number(emp.hours || 0));
  const rawMax = Math.max(...employees.map((e) => valueOf(e)), showPoints ? 100 : 1);
  const niceStep = showPoints ? 10 : rawMax <= 10 ? 2 : rawMax <= 30 ? 5 : 10;
  const maxY = showPoints ? 100 : Math.ceil(rawMax / niceStep) * niceStep || niceStep;
  const tickCount = Math.max(2, Math.round(maxY / niceStep));
  const gridYs = Array.from({ length: tickCount + 1 }, (_, i) => {
    const value = (maxY / tickCount) * i;
    return {
      value,
      y: pad.top + plotH - (value / maxY) * plotH,
      label: String(Math.round(value)),
    };
  });

  const slot = plotW / count;
  const barWidth = Math.min(46, Math.max(16, slot * 0.52));
  const axisY = pad.top + plotH;

  return (
    <div className="att-analytics-emp-bars-wrap">
      <svg
        className="att-analytics-line-svg"
        viewBox={`0 0 ${width} ${height}`}
        role="img"
        aria-label={
          showPoints
            ? `Attendance KPI points${periodLabel ? ` ${periodLabel}` : ''}`
            : `Employee working hours${periodLabel ? ` ${periodLabel}` : ''}`
        }
      >
        {gridYs.map((g) => (
          <g key={`grid-${g.label}`}>
            <line x1={pad.left} x2={width - pad.right} y1={g.y} y2={g.y} className="att-analytics-line-grid" />
            <text x={pad.left - 8} y={g.y + 3} textAnchor="end" className="att-analytics-line-axis">
              {g.label}
            </text>
          </g>
        ))}
        {employees.map((emp, idx) => {
          const value = valueOf(emp);
          const hours = Number(emp.hours) || 0;
          const points = Number(emp.kpiPoints) || 0;
          const barH = Math.max(value > 0 ? 4 : 0, (value / maxY) * plotH);
          const cx = pad.left + slot * idx + slot / 2;
          const x = cx - barWidth / 2;
          const y = axisY - barH;
          const hoursLabel = Number.isInteger(hours) ? `${hours}h` : `${hours.toFixed(1)}h`;
          const pointsLabel = `${Number.isInteger(points) ? points : points.toFixed(0)} pts`;
          const topLabel = showPoints ? pointsLabel : hoursLabel;
          const displayName = String(emp.name || '').trim();
          const bd = emp.kpiBreakdown || {};
          const tipParts = [
            `${displayName}: ${pointsLabel} / 100`,
            `Att ${Number(bd.attendance || 0)} ù Daily ${Number(bd.dailyTasks || 0)} ù Weekly ${Number(bd.weeklyTasks || 0)}`,
            hoursLabel,
            emp.missedOuts > 0 ? `${emp.missedOuts} missed sign-out${emp.missedOuts === 1 ? '' : 's'}` : '',
            emp.lateIns > 0 ? `${emp.lateIns} late sign-in${emp.lateIns === 1 ? '' : 's'}` : '',
          ].filter(Boolean);
          return (
            <g key={emp.id != null ? `emp-${emp.id}` : `emp-${idx}`}>
              <rect x={x} y={y} width={barWidth} height={barH} rx={8} ry={8} fill={emp.color || '#3b82f6'}>
                <title>{tipParts.join(' ù ')}</title>
              </rect>
              <text x={cx} y={y - 8} textAnchor="middle" className="att-analytics-emp-bar-value">
                {topLabel}
              </text>
              <text x={cx} y={axisY + 14} textAnchor="middle" className="att-analytics-emp-bar-initials">
                {emp.initials}
              </text>
              <text
                x={cx}
                y={axisY + 28}
                textAnchor="end"
                dominantBaseline="middle"
                transform={`rotate(-90 ${cx} ${axisY + 28})`}
                className="att-analytics-emp-bar-name"
              >
                {displayName}
              </text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}
export default function AttendanceAnalytics({ data }) {
  const initial = data || {};
  const [state, setState] = useState(() => ({
    period: Number(initial.period || 30),
    scope: initial.scope === 'team' ? 'team' : 'personal',
    scopeOptions: initial.scopeOptions || [
      { value: 'personal', label: 'Personal' },
      { value: 'team', label: 'Team' },
    ],
    periodOptions: initial.periodOptions || [
      { value: 7, label: '7 Days' },
      { value: 30, label: '30 Days' },
      { value: 90, label: '90 Days' },
    ],
    metrics: initial.metrics || {},
    charts: initial.charts || { daily: { labels: [], values: [] }, weekly: { labels: [], values: [] } },
    insights: initial.insights || [],
        history: initial.history || [],
    range: initial.range || {},
    personalKpi: initial.personalKpi || null,
  }));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [barGrain, setBarGrain] = useState('monthly');
  const [employeeFilter, setEmployeeFilter] = useState('all');
  const [openMetric, setOpenMetric] = useState(null);
  const [showKpiAbout, setShowKpiAbout] = useState(false);
  const kpiAboutRef = useRef(null);

  useEffect(() => {
    if (!showKpiAbout && openMetric == null) return undefined;
    const onDocPointer = (event) => {
      const target = event.target;
      if (!(target instanceof Element)) {
        setShowKpiAbout(false);
        setOpenMetric(null);
        return;
      }
      if (showKpiAbout) {
        const aboutRoot = kpiAboutRef.current;
        if (!aboutRoot || !aboutRoot.contains(target)) {
          setShowKpiAbout(false);
        }
      }
      if (openMetric != null && !target.closest('.att-analytics-kpi-wrap')) {
        setOpenMetric(null);
      }
    };
    const onKey = (event) => {
      if (event.key === 'Escape') {
        setShowKpiAbout(false);
        setOpenMetric(null);
      }
    };
    // Capture phase so ERP chrome / stopPropagation does not block dismiss.
    document.addEventListener('pointerdown', onDocPointer, true);
    document.addEventListener('keydown', onKey, true);
    return () => {
      document.removeEventListener('pointerdown', onDocPointer, true);
      document.removeEventListener('keydown', onKey, true);
    };
  }, [showKpiAbout, openMetric]);

  const metrics = state.metrics || {};
  const daily = state.charts?.daily || { labels: [], values: [] };
  const weekly = state.charts?.weekly || { labels: [], values: [] };
  const teamLine = state.charts?.teamLine || { labels: [], series: [], title: '' };
  const teamPunct = teamLine.punctuality || null;
  const teamKpi = teamLine.kpi || null;
  const personalKpi = state.personalKpi || initial.personalKpi || null;
  const isTeam = state.scope === 'team';

  const monthTitle = useMemo(() => {
    const start = state.range?.start;
    if (!start) return 'this month';
    const d = new Date(`${start}T12:00:00`);
    if (Number.isNaN(d.getTime())) return 'this month';
    return d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
  }, [state.range?.start]);

  const employeeTotals = useMemo(() => {
    const series = teamLine.series || [];
    const filtered =
      employeeFilter === 'all'
        ? series
        : series.filter((s) => String(s.id) === String(employeeFilter));
    return aggregateEmployeeTotals(filtered, teamLine.labels || [], barGrain);
  }, [teamLine.series, teamLine.labels, barGrain, employeeFilter]);

  const metricCards = useMemo(() => {
    const presentHint = isTeam
      ? `${Number(metrics.presentDays || 0)} clock-ins / ${Number(metrics.expectedSlots || 0)} slots`
      : `${Number(metrics.presentDays || 0)} of ${Number(metrics.workingDays || 0)} days`;

    return [
      {
        key: 'rate',
        label: 'Attendance rate',
        value: `${Number(metrics.attendanceRate || 0)}%`,
        hint: presentHint,
        icon: 'fa-chart-line',
        tone: 'violet',
      },
      {
        key: 'punctual',
        label: 'Punctuality',
        value: `${Number(metrics.punctualityScore || 0)}%`,
        hint: isTeam
          ? `${Number(metrics.lateDays || 0)} late ù ${Number(metrics.missedSignOuts || 0)} missed outs`
          : `${Number(metrics.lateDays || 0)} late ù ${Number(metrics.missedSignOuts || 0)} missed outs`,
        icon: 'fa-clock',
        tone: 'green',
      },
      {
        key: 'streak',
        label: isTeam ? 'Active members' : 'Longest streak',
        value: isTeam
          ? `${Number(metrics.activeMembers || 0)}/${Number(metrics.teamHeadcount || 0)}`
          : String(Number(metrics.longestStreak || 0)),
        hint: isTeam
          ? `People who clocked in`
          : `Current: ${Number(metrics.currentStreak || 0)} days`,
        icon: isTeam ? 'fa-users' : 'fa-fire',
        tone: 'amber',
      },
      {
        key: 'avg',
        label: 'Avg hours/day',
        value: `${Number(metrics.avgHoursPerDay || 0)}h`,
        hint: `Total: ${Number(metrics.totalHours || 0)}h`,
        icon: 'fa-hourglass-half',
        tone: 'sky',
      },
    ];
  }, [metrics, isTeam]);

  async function loadAnalytics({ period, scope } = {}) {
    const nextPeriod = Number(period ?? state.period);
    const nextScope = (scope ?? state.scope) === 'team' ? 'team' : 'personal';
    if (nextPeriod === state.period && nextScope === state.scope && period == null && scope == null) {
      return;
    }
    setBusy(true);
    setError('');
    try {
      const result = await postAttendanceAction({
        action: 'analytics',
        period: nextPeriod,
        scope: nextScope,
      });
      if (!result.success) {
        setError(result.message || 'Failed to load analytics.');
        return;
      }
      const payload = result.data || {};
      setState((cur) => ({
        ...cur,
        period: Number(payload.period || nextPeriod),
        scope: payload.scope === 'team' ? 'team' : 'personal',
        scopeOptions: payload.scopeOptions || cur.scopeOptions,
        periodOptions: payload.periodOptions || cur.periodOptions,
        metrics: payload.metrics || {},
        charts: payload.charts || cur.charts,
        insights: payload.insights || [],
        history: payload.history || [],
        range: payload.range || {},
        personalKpi: payload.personalKpi || null,
      }));
      const url = new URL(window.location.href);
      url.searchParams.set('period', String(payload.period || nextPeriod));
      url.searchParams.set('scope', payload.scope === 'team' ? 'team' : 'personal');
      url.searchParams.set('module', 'attendance');
      window.history.replaceState({}, '', url.toString());
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load analytics.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="att-shell att-page-analytics">
      <div className="att-analytics">
        <div className="att-analytics-header">
          <div>
            <p className="att-analytics-sub att-analytics-sub--solo">
              {state.range?.start && state.range?.end
                ? `${formatDate(state.range.start)} - ${formatDate(state.range.end)}`
                : 'Your attendance performance'}
            </p>
          </div>
          <div className="att-analytics-header-controls">
            <div className="att-analytics-periods" role="tablist" aria-label="Stats scope">
              {(state.scopeOptions || []).map((opt) => (
                <button
                  key={opt.value}
                  type="button"
                  role="tab"
                  aria-selected={state.scope === opt.value}
                  className={`att-analytics-period${state.scope === opt.value ? ' is-active' : ''}`}
                  disabled={busy}
                  onClick={() => loadAnalytics({ scope: opt.value })}
                >
                  {opt.label}
                </button>
              ))}
            </div>
            {!isTeam ? (
              <div className="att-analytics-periods" role="tablist" aria-label="Period">
                {(state.periodOptions || []).map((opt) => (
                  <button
                    key={opt.value}
                    type="button"
                    role="tab"
                    aria-selected={Number(state.period) === Number(opt.value)}
                    className={`att-analytics-period${Number(state.period) === Number(opt.value) ? ' is-active' : ''}`}
                    disabled={busy}
                    onClick={() => loadAnalytics({ period: opt.value })}
                  >
                    {opt.label}
                  </button>
                ))}
              </div>
            ) : null}
          </div>
        </div>

        {error ? <div className="att-desk-error">{error}</div> : null}

        <section className="att-analytics-kpi-grid" aria-label="Summary">
          {metricCards.map((card) => {
            const isOpen = openMetric === card.key;
            return (
              <div className={`att-analytics-kpi-wrap${isOpen ? ' is-open' : ''}`} key={card.key}>
                <button
                  type="button"
                  className={`att-analytics-kpi att-analytics-kpi--chip att-analytics-kpi--${card.tone}${isOpen ? ' is-active' : ''}`}
                  aria-expanded={isOpen}
                  onClick={() => setOpenMetric((cur) => (cur === card.key ? null : card.key))}
                >
                  <span className="att-analytics-kpi-icon" aria-hidden="true">
                    <i className={`fas ${card.icon}`} />
                  </span>
                  <span className="att-analytics-kpi-text">
                    <span className="att-analytics-kpi-label">{card.label}</span>
                    <span className="att-analytics-kpi-simple">{busy ? '...' : card.value}</span>
                  </span>
                </button>
                {isOpen ? (
                  <div className="att-analytics-kpi-pop" role="dialog" aria-label={card.label}>
                    <div className="att-analytics-kpi-value">{busy ? '...' : card.value}</div>
                    <div className="att-analytics-kpi-hint">{card.hint}</div>
                  </div>
                ) : null}
              </div>
            );
          })}
        </section>

        <section className={`att-analytics-charts${isTeam ? ' att-analytics-charts--single' : ''}`}>
          {isTeam ? (
            <div className="att-analytics-chart-card">
              <div className="att-analytics-chart-head">
                <div>
                  <div className="att-analytics-chart-title-row">
                    <h2 className="att-analytics-chart-title">Attendance KPI Points</h2>
                    <div className="att-analytics-about-wrap" ref={kpiAboutRef}>
                      <button
                        type="button"
                        className={`att-analytics-about-btn${showKpiAbout ? ' is-active' : ''}`}
                        aria-label="About attendance KPI points"
                        aria-expanded={showKpiAbout}
                        onClick={(e) => {
                          e.stopPropagation();
                          setShowKpiAbout((v) => !v);
                        }}
                      >
                        <i className="fas fa-info-circle" aria-hidden="true" />
                      </button>
                      {showKpiAbout ? (
                        <>
                          <button
                            type="button"
                            className="att-analytics-about-backdrop"
                            aria-label="Close KPI info"
                            onClick={() => setShowKpiAbout(false)}
                          />
                          <div className="att-analytics-about-pop" role="dialog" aria-label="KPI scoring info">
                            {`100-pt score in ${monthTitle}: Attendance 40 + Daily todos 30 + Weekly tasks 30`}
                          </div>
                        </>
                      ) : null}
                    </div>
                  </div>
                  {teamKpi ? (
                    <div className="att-analytics-punct-strip" title={teamKpi.note || ''}>
                      <div className="att-analytics-punct-item">
                        <span className="att-analytics-punct-label">Avg attendance KPI</span>
                        <strong>{Number(teamKpi.average || 0).toFixed(0)}/100</strong>
                        <span className="att-analytics-punct-meta">Att 40 / Daily 30 / Weekly 30</span>
                      </div>
                      <div className="att-analytics-punct-item">
                        <span className="att-analytics-punct-label">Top attendance KPI</span>
                        <strong>
                          {teamKpi.top ? `${Number(teamKpi.top.score || 0).toFixed(0)}/100` : '-'}
                        </strong>
                        <span className="att-analytics-punct-meta">
                          {teamKpi.top
                            ? `${teamKpi.top.name} / ${teamKpi.top.grade || ''}`
                            : 'No scored employees yet'}
                        </span>
                      </div>
                      <div className="att-analytics-punct-item">
                        <span className="att-analytics-punct-label">Missed sign-outs</span>
                        <strong>{Number(teamPunct?.missedSignOuts || 0)}</strong>
                        <span className="att-analytics-punct-meta">
                          {Number(teamPunct?.lateIns || 0)} late sign-ins
                        </span>
                      </div>
                    </div>
                  ) : null}
                </div>
                <div className="att-analytics-chart-controls">
                  <div className="att-analytics-periods att-analytics-periods--blue" role="tablist" aria-label="Bar range">
                    {[
                      { value: 'daily', label: 'Daily' },
                      { value: 'weekly', label: 'Weekly' },
                      { value: 'monthly', label: 'Monthly' },
                    ].map((opt) => (
                      <button
                        key={opt.value}
                        type="button"
                        role="tab"
                        aria-selected={barGrain === opt.value}
                        className={`att-analytics-period${barGrain === opt.value ? ' is-active' : ''}`}
                        onClick={() => setBarGrain(opt.value)}
                      >
                        {opt.label}
                      </button>
                    ))}
                  </div>
                  <label className="att-analytics-emp-filter">
                    <i className="fas fa-users" aria-hidden="true" />
                    <select
                      value={employeeFilter}
                      onChange={(e) => setEmployeeFilter(e.target.value)}
                      aria-label="Filter employees"
                    >
                      <option value="all">All Employees</option>
                      {(teamLine.series || []).map((s) => (
                        <option key={s.id || s.name} value={String(s.id)}>
                          {s.name}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>
              </div>
              <EmployeeHoursBarChart
                employees={employeeTotals}
                periodLabel={monthTitle}
                metric="points"
              />
            </div>
          ) : (
            <>
              {personalKpi ? (
                <div className="att-analytics-chart-card att-analytics-chart-card--kpi">
                  <h2 className="att-analytics-chart-title">My attendance KPI points</h2>
                  <p className="att-analytics-chart-sub">
                    Attendance 40 + Daily todos (target 5) 30 + Weekly tasks (target 7) 30 = 100
                  </p>
                  <div className="att-analytics-punct-strip">
                    <div className="att-analytics-punct-item">
                      <span className="att-analytics-punct-label">Total</span>
                      <strong>{Number(personalKpi.kpiPoints || 0).toFixed(0)}/100</strong>
                      <span className="att-analytics-punct-meta">{personalKpi.grade || ''}</span>
                    </div>
                    <div className="att-analytics-punct-item">
                      <span className="att-analytics-punct-label">Attendance</span>
                      <strong>{Number(personalKpi.kpiBreakdown?.attendance || 0).toFixed(0)}/40</strong>
                      <span className="att-analytics-punct-meta">Sign-in / sign-out score</span>
                    </div>
                    <div className="att-analytics-punct-item">
                      <span className="att-analytics-punct-label">Daily tasks</span>
                      <strong>{Number(personalKpi.kpiBreakdown?.dailyTasks || 0).toFixed(0)}/30</strong>
                      <span className="att-analytics-punct-meta">
                        {Number(personalKpi.kpiBreakdown?.dailyCompleted || 0)} completed
                      </span>
                    </div>
                    <div className="att-analytics-punct-item">
                      <span className="att-analytics-punct-label">Weekly tasks</span>
                      <strong>{Number(personalKpi.kpiBreakdown?.weeklyTasks || 0).toFixed(0)}/30</strong>
                      <span className="att-analytics-punct-meta">
                        {Number(personalKpi.kpiBreakdown?.weeklyCompleted || 0)}/
                        {Number(personalKpi.kpiBreakdown?.weeklyTotal || 0)} done
                      </span>
                    </div>
                  </div>
                </div>
              ) : null}
              <div className="att-analytics-chart-card">
                <h2 className="att-analytics-chart-title">Daily hours worked</h2>
                <BarChart labels={daily.labels || []} values={daily.values || []} color="#0284c7" />
              </div>
              <div className="att-analytics-chart-card">
                <h2 className="att-analytics-chart-title">Weekly distribution</h2>
                <BarChart labels={weekly.labels || []} values={weekly.values || []} color="#ea580c" />
              </div>
            </>
          )}
        </section>

        {(state.insights || []).length > 0 ? (
          <section className="att-analytics-insights">
            <h2 className="att-analytics-chart-title">Insights</h2>
            <div className="att-analytics-insight-list">
              {state.insights.map((item, idx) => (
                <div className={`att-analytics-insight att-analytics-insight--${item.tone || 'info'}`} key={`${item.title}-${idx}`}>
                  <div className="att-analytics-insight-icon" aria-hidden="true">
                    <i className={`fas ${item.icon || 'fa-lightbulb'}`} />
                  </div>
                  <div>
                    <div className="att-analytics-insight-title">{item.title}</div>
                    <div className="att-analytics-insight-body">{item.body}</div>
                  </div>
                </div>
              ))}
            </div>
          </section>
        ) : null}

        <section className="att-desk-results">
          <div className="att-desk-results-head">
            <h2 className="att-analytics-chart-title" style={{ margin: 0 }}>
              {isTeam ? 'Team records' : 'Recent records'}
            </h2>
            <span className="att-desk-results-count">
              {busy
                ? 'Loading...'
                : `${(state.history || []).length} record${(state.history || []).length === 1 ? '' : 's'}`}
            </span>
          </div>
          <div className="att-desk-table-wrap">
            <table className={`att-desk-table${isTeam ? '' : ' att-desk-table--activity'}`}>
              <thead>
                <tr>
                  <th>Date</th>
                  {isTeam ? <th>Employee</th> : null}
                  <th>Status</th>
                  <th>In</th>
                  <th>Out</th>
                  <th>Hours</th>
                  <th>OT</th>
                </tr>
              </thead>
              <tbody>
                {(state.history || []).length === 0 ? (
                  <tr>
                    <td colSpan={isTeam ? 7 : 6}>
                      <div className="att-desk-empty">
                        <div className="att-desk-empty-icon" aria-hidden="true">
                          <i className="fas fa-chart-bar" />
                        </div>
                        <p className="att-desk-empty-title">No records in this period</p>
                        <p className="att-desk-empty-sub">Try a longer period or clock in to start tracking.</p>
                      </div>
                    </td>
                  </tr>
                ) : (
                  (state.history || []).map((row) => (
                    <tr key={`${row.user_id || 'me'}-${row.date}-${row.time_in || ''}`}>
                      <td>
                        <strong>{formatDate(row.date)}</strong>
                      </td>
                      {isTeam ? (
                        <td>
                          <div className="att-desk-emp-name">{row.full_name || row.username || '-'}</div>
                          {row.username && row.full_name ? (
                            <div className="att-desk-emp-user">{row.username}</div>
                          ) : null}
                        </td>
                      ) : null}
                      <td>
                        <span className={`att-desk-status ${statusClass(row.status)}`}>{row.status || '-'}</span>
                      </td>
                      <td>{formatTime(row.time_in) || <span className="att-desk-muted">--:--</span>}</td>
                      <td>
                        {formatTime(row.time_out) || <span className="att-desk-muted">--:--</span>}
                      </td>
                      <td>{row.total_hours != null ? `${row.total_hours}h` : '-'}</td>
                      <td>
                        {row.overtime_hours && Number(row.overtime_hours) > 0 ? (
                          `+${row.overtime_hours}`
                        ) : (
                          <span className="att-desk-muted">--</span>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  );
}
