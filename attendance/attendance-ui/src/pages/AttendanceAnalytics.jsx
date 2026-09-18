import React, { useEffect, useMemo, useRef, useState } from 'react';
import { postAttendanceAction } from '../api';
import '../attendance-analytics.css';

function formatDate(value) {
  if (!value) return '-';
  const d = new Date(`${value}T12:00:00`);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
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

function formatShortDate(value) {
  if (!value) return '-';
  const d = new Date(`${value}T12:00:00`);
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function aggregateEmployeeTotals(series = [], labels = [], fromDate = '', toDate = '') {
  const n = labels.length;
  if (!n) return [];

  let startIdx = 0;
  let endIdx = n - 1;
  if (fromDate || toDate) {
    const from = fromDate || labels[0];
    const to = toDate || labels[n - 1];
    startIdx = labels.findIndex((d) => String(d) >= String(from));
    if (startIdx < 0) startIdx = 0;
    endIdx = labels.findLastIndex
      ? labels.findLastIndex((d) => String(d) <= String(to))
      : (() => {
          let idx = -1;
          for (let i = 0; i < n; i += 1) {
            if (String(labels[i]) <= String(to)) idx = i;
          }
          return idx;
        })();
    if (endIdx < 0) endIdx = n - 1;
    if (startIdx > endIdx) {
      startIdx = 0;
      endIdx = n - 1;
    }
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
            displayName,
            showPoints ? `${pointsLabel} / 100` : hoursLabel,
            showPoints
              ? `Att ${Number(bd.attendance || 0)} / Daily ${Number(bd.dailyTasks || 0)} / Weekly ${Number(bd.weeklyTasks || 0)}`
              : `${pointsLabel} KPI (month)`,
            emp.missedOuts > 0 ? `${emp.missedOuts} missed sign-out${emp.missedOuts === 1 ? '' : 's'}` : '',
            emp.lateIns > 0 ? `${emp.lateIns} late sign-in${emp.lateIns === 1 ? '' : 's'}` : '',
          ].filter(Boolean);
          return (
            <g key={emp.id != null ? `emp-${emp.id}` : `emp-${idx}`}>
              <rect
                className="att-analytics-emp-bar"
                x={x}
                y={y}
                width={barWidth}
                height={barH}
                rx={8}
                ry={8}
                fill={emp.color || '#3b82f6'}
              >
                <title>{tipParts.join(' / ')}</title>
              </rect>
              <text x={cx} y={y - 8} textAnchor="middle" className="att-analytics-emp-bar-value">
                {topLabel}
              </text>
              <text
                x={cx}
                y={axisY + 10}
                textAnchor="end"
                dominantBaseline="middle"
                transform={`rotate(-90 ${cx} ${axisY + 10})`}
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
    rangeBounds: initial.rangeBounds || {},
    personalKpi: initial.personalKpi || null,
  }));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [barFrom, setBarFrom] = useState(() => String(initial.range?.start || ''));
  const [barTo, setBarTo] = useState(() => String(initial.range?.end || ''));
  const rangeBounds = state.rangeBounds || initial.rangeBounds || {};
  const dateMinBound = String(rangeBounds.min || '');
  const dateMaxBound = String(rangeBounds.max || '');
  const [barMetric, setBarMetric] = useState('hours');
  const [employeeFilter, setEmployeeFilter] = useState('all');
  const [openMetric, setOpenMetric] = useState(null);
  const [openPunct, setOpenPunct] = useState(null);
  const [showKpiAbout, setShowKpiAbout] = useState(false);
  const [showAttDistribution, setShowAttDistribution] = useState(false);
  const [showMetricDetails, setShowMetricDetails] = useState(false);
  const [showDailyTaskList, setShowDailyTaskList] = useState(false);
  const [showWeeklyTaskList, setShowWeeklyTaskList] = useState(false);
  const kpiAboutRef = useRef(null);

  useEffect(() => {
    const start = String(state.range?.start || '');
    const end = String(state.range?.end || '');
    if (start) setBarFrom(start);
    if (end) setBarTo(end);
  }, [state.range?.start, state.range?.end]);

  useEffect(() => {
    if (!showKpiAbout && openMetric == null && openPunct == null) return undefined;
    const onDocPointer = (event) => {
      const target = event.target;
      if (!(target instanceof Element)) {
        setShowKpiAbout(false);
        setOpenMetric(null);
        setOpenPunct(null);
        setShowAttDistribution(false);
        setShowMetricDetails(false);
        setShowDailyTaskList(false);
        setShowWeeklyTaskList(false);
        return;
      }
      if (showKpiAbout) {
        const aboutRoot = kpiAboutRef.current;
        if (!aboutRoot || !aboutRoot.contains(target)) {
          setShowKpiAbout(false);
        }
      }
      if (openMetric != null && !target.closest('.att-analytics-kpi-wrap') && !target.closest('.att-analytics-punct-modal-card')) {
        setOpenMetric(null);
        setShowMetricDetails(false);
      }
      if (openPunct != null && !target.closest('.att-analytics-punct-item') && !target.closest('.att-analytics-punct-modal-card')) {
        setOpenPunct(null);
        setShowAttDistribution(false);
        setShowDailyTaskList(false);
        setShowWeeklyTaskList(false);
      }
    };
    const onKey = (event) => {
      if (event.key === 'Escape') {
        setShowKpiAbout(false);
        setOpenMetric(null);
        setOpenPunct(null);
        setShowAttDistribution(false);
        setShowMetricDetails(false);
        setShowDailyTaskList(false);
        setShowWeeklyTaskList(false);
      }
    };
    document.addEventListener('pointerdown', onDocPointer, true);
    document.addEventListener('keydown', onKey, true);
    return () => {
      document.removeEventListener('pointerdown', onDocPointer, true);
      document.removeEventListener('keydown', onKey, true);
    };
  }, [showKpiAbout, openMetric, openPunct]);

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
    return aggregateEmployeeTotals(filtered, teamLine.labels || [], barFrom, barTo);
  }, [teamLine.series, teamLine.labels, barFrom, barTo, employeeFilter]);

  const chartMinDate = String(dateMinBound || state.range?.start || (teamLine.labels || [])[0] || '');
  const chartMaxDate = String(
    dateMaxBound || state.range?.end || (teamLine.labels || [])[(teamLine.labels || []).length - 1] || ''
  );

  const barRangeLabel = useMemo(() => {
    if (barFrom && barTo && barFrom === barTo) return formatShortDate(barFrom);
    if (barFrom || barTo) {
      return `${formatShortDate(barFrom || chartMinDate)} - ${formatShortDate(barTo || chartMaxDate)}`;
    }
    return monthTitle;
  }, [barFrom, barTo, chartMinDate, chartMaxDate, monthTitle]);

  const metricCards = useMemo(() => {
    const presentHint = isTeam
      ? `${Number(metrics.presentDays || 0)} clock-ins / ${Number(metrics.expectedSlots || 0)} slots`
      : `${Number(metrics.presentDays || 0)} of ${Number(metrics.workingDays || 0)} days`;

    return [
      {
        key: 'rate',
        label: 'Attendance rate',
        value: `${Number(metrics.attendanceRate || 0)}%`,
        lead: isTeam
          ? 'Share of expected team clock-in slots filled in this period.'
          : 'Share of working days with a clock-in in this period.',
        details: [
          {
            label: isTeam ? 'Clock-ins' : 'Present days',
            value: String(Number(metrics.presentDays || 0)),
          },
          {
            label: isTeam ? 'Expected slots' : 'Working days',
            value: String(Number(isTeam ? metrics.expectedSlots || 0 : metrics.workingDays || 0)),
          },
          {
            label: 'Attendance rate',
            value: `${Number(metrics.attendanceRate || 0)}%`,
          },
        ],
        note: presentHint,
        icon: 'fa-chart-line',
        tone: 'violet',
        detailsToggle: 'Attendance breakdown',
      },
      {
        key: 'punctual',
        label: 'Punctuality',
        value: `${Number(metrics.punctualityScore || 0)}%`,
        lead: 'Sign-in and sign-out punctuality score for this period.',
        details: [
          {
            label: 'Punctuality score',
            value: `${Number(metrics.punctualityScore || 0)}%`,
          },
          {
            label: 'Late sign-ins',
            value: String(Number(metrics.lateDays || 0)),
          },
          {
            label: 'Missed sign-outs',
            value: String(Number(metrics.missedSignOuts || 0)),
          },
          {
            label: 'Longest streak',
            value: `${Number(metrics.longestStreak || 0)} days`,
          },
          {
            label: 'Current streak',
            value: `${Number(metrics.currentStreak || 0)} days`,
          },
        ],
        note: 'Late arrivals and forgotten clock-outs reduce this score.',
        icon: 'fa-clock',
        tone: 'green',
        detailsToggle: 'Punctuality & streak',
      },
      {
        key: isTeam ? 'members' : 'overtime',
        label: isTeam ? 'Active members' : 'Overtime',
        value: isTeam
          ? `${Number(metrics.activeMembers || 0)}/${Number(metrics.teamHeadcount || 0)}`
          : `${Number(metrics.totalOt || 0)}h`,
        lead: isTeam
          ? 'People who clocked in at least once during this period.'
          : 'Total overtime hours recorded in this period.',
        details: isTeam
          ? [
              {
                label: 'Active members',
                value: String(Number(metrics.activeMembers || 0)),
              },
              {
                label: 'Team headcount',
                value: String(Number(metrics.teamHeadcount || 0)),
              },
              {
                label: 'Coverage',
                value:
                  Number(metrics.teamHeadcount || 0) > 0
                    ? `${Math.round(
                        (Number(metrics.activeMembers || 0) / Number(metrics.teamHeadcount || 0)) * 100
                      )}%`
                    : '-',
              },
              {
                label: 'Team overtime',
                value: `${Number(metrics.totalOt || 0)}h`,
              },
            ]
          : [
              {
                label: 'Total overtime',
                value: `${Number(metrics.totalOt || 0)}h`,
              },
              {
                label: 'Present days',
                value: String(Number(metrics.presentDays || 0)),
              },
              {
                label: 'Avg OT / present day',
                value:
                  Number(metrics.presentDays || 0) > 0
                    ? `${(Number(metrics.totalOt || 0) / Number(metrics.presentDays || 0)).toFixed(1)}h`
                    : '0h',
              },
            ],
        note: isTeam
          ? 'Admins are excluded from team headcount.'
          : 'Overtime is hours past the configured end time.',
        icon: isTeam ? 'fa-users' : 'fa-business-time',
        tone: 'amber',
        detailsToggle: isTeam ? 'Member coverage' : 'Overtime details',
        href: !isTeam && initial.links?.overtime ? initial.links.overtime : null,
      },
      {
        key: 'avg',
        label: 'Avg hours/day',
        value: `${Number(metrics.avgHoursPerDay || 0)}h`,
        lead: 'Average hours worked per attendance day in this period.',
        details: [
          {
            label: 'Avg hours/day',
            value: `${Number(metrics.avgHoursPerDay || 0)}h`,
          },
          {
            label: 'Total hours',
            value: `${Number(metrics.totalHours || 0)}h`,
          },
          {
            label: 'Present days',
            value: String(Number(metrics.presentDays || 0)),
          },
        ],
        note: 'Remember to clock out so totals stay accurate.',
        icon: 'fa-hourglass-half',
        tone: 'sky',
        detailsToggle: 'Hours breakdown',
      },
    ];
  }, [metrics, isTeam, initial.links?.overtime]);

  const openMetricCard = useMemo(
    () => metricCards.find((card) => card.key === openMetric) || null,
    [metricCards, openMetric]
  );

  async function loadAnalytics({ period, scope, start, end } = {}) {
    const nextPeriod = Number(period ?? state.period);
    const nextScope = (scope ?? state.scope) === 'team' ? 'team' : 'personal';
    const hasExplicitRange = start !== undefined && end !== undefined;
    const nextStart = hasExplicitRange ? String(start || '') : '';
    const nextEnd = hasExplicitRange ? String(end || '') : '';
    const usingCustomRange = nextScope === 'team' && hasExplicitRange && nextStart !== '' && nextEnd !== '';
    if (
      nextPeriod === state.period &&
      nextScope === state.scope &&
      period == null &&
      scope == null &&
      !hasExplicitRange
    ) {
      return;
    }
    if (
      usingCustomRange &&
      nextStart === String(state.range?.start || '') &&
      nextEnd === String(state.range?.end || '') &&
      nextScope === state.scope
    ) {
      return;
    }
    setBusy(true);
    setError('');
    try {
      const payloadBody = {
        action: 'analytics',
        period: nextPeriod,
        scope: nextScope,
      };
      if (usingCustomRange) {
        payloadBody.start = nextStart;
        payloadBody.end = nextEnd;
      }
      const result = await postAttendanceAction(payloadBody);
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
        rangeBounds: payload.rangeBounds || cur.rangeBounds || {},
        personalKpi: payload.personalKpi || null,
      }));
      if (payload.range?.start) setBarFrom(String(payload.range.start));
      if (payload.range?.end) setBarTo(String(payload.range.end));
      const url = new URL(window.location.href);
      url.searchParams.set('period', String(payload.period || nextPeriod));
      url.searchParams.set('scope', payload.scope === 'team' ? 'team' : 'personal');
      url.searchParams.set('module', 'attendance');
      if (payload.scope === 'team' && payload.range?.start && payload.range?.end) {
        url.searchParams.set('start', String(payload.range.start));
        url.searchParams.set('end', String(payload.range.end));
      } else {
        url.searchParams.delete('start');
        url.searchParams.delete('end');
      }
      window.history.replaceState({}, '', url.toString());
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load analytics.');
    } finally {
      setBusy(false);
    }
  }

  const onBarFromChange = (value) => {
    const next = String(value || '');
    const nextTo = barTo && next && next > barTo ? next : barTo;
    setBarFrom(next);
    if (nextTo !== barTo) setBarTo(nextTo);
    if (next && nextTo) {
      loadAnalytics({ scope: 'team', start: next, end: nextTo });
    }
  };

  const onBarToChange = (value) => {
    const next = String(value || '');
    const nextFrom = barFrom && next && next < barFrom ? next : barFrom;
    setBarTo(next);
    if (nextFrom !== barFrom) setBarFrom(nextFrom);
    if (next && nextFrom) {
      loadAnalytics({ scope: 'team', start: nextFrom, end: next });
    }
  };

  return (
    <div className="att-shell att-page-analytics">
      <div className="att-analytics">
        <div className="att-analytics-header">
          <div className="att-analytics-header-range">
            {isTeam ? (
              <div className="att-analytics-date-range" aria-label="Stats date range">
                <label className="att-analytics-date-field">
                  <input
                    type="date"
                    value={barFrom || chartMinDate}
                    min={chartMinDate || undefined}
                    max={barTo || chartMaxDate || undefined}
                    onChange={(e) => onBarFromChange(e.target.value)}
                    aria-label="From date"
                  />
                </label>
                <label className="att-analytics-date-field">
                  <input
                    type="date"
                    value={barTo || chartMaxDate}
                    min={barFrom || chartMinDate || undefined}
                    max={chartMaxDate || undefined}
                    onChange={(e) => onBarToChange(e.target.value)}
                    aria-label="To date"
                  />
                </label>
              </div>
            ) : (
              <p className="att-analytics-sub att-analytics-sub--solo">
                {state.range?.start && state.range?.end
                  ? `${formatDate(state.range.start)} - ${formatDate(state.range.end)}`
                  : 'Your attendance performance'}
              </p>
            )}
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
                  onClick={() => {
                    if (card.href) {
                      window.location.href = card.href;
                      return;
                    }
                    setShowMetricDetails(false);
                    setOpenMetric((cur) => (cur === card.key ? null : card.key));
                  }}
                >
                  <span className="att-analytics-kpi-icon" aria-hidden="true">
                    <i className={`fas ${card.icon}`} />
                  </span>
                  <span className="att-analytics-kpi-text">
                    <span className="att-analytics-kpi-label">{card.label}</span>
                    <span className="att-analytics-kpi-simple">{busy ? '...' : card.value}</span>
                  </span>
                </button>
              </div>
            );
          })}
        </section>
        {openMetricCard ? (
          <div className="att-analytics-punct-modal" role="dialog" aria-modal="true">
            <button
              type="button"
              className="att-analytics-punct-modal-backdrop"
              aria-label="Close details"
              onClick={() => {
                setShowMetricDetails(false);
                setOpenMetric(null);
              }}
            />
            <div className="att-analytics-punct-modal-card">
              <div className="att-analytics-punct-modal-head">
                <h3 className="att-analytics-punct-modal-title">{openMetricCard.label}</h3>
                <button
                  type="button"
                  className="att-analytics-punct-modal-close"
                  aria-label="Close"
                  onClick={() => {
                    setShowMetricDetails(false);
                    setOpenMetric(null);
                  }}
                >
                  <i className="fas fa-times" aria-hidden="true" />
                </button>
              </div>
              <div className="att-analytics-punct-modal-value">
                {busy ? '...' : openMetricCard.value}
              </div>
              {openMetricCard.lead ? (
                <p className="att-analytics-punct-modal-lead">{openMetricCard.lead}</p>
              ) : null}
              <button
                type="button"
                className="att-analytics-punct-modal-action"
                onClick={() => setShowMetricDetails((v) => !v)}
              >
                <i
                  className={`fas ${showMetricDetails ? 'fa-chevron-up' : 'fa-chart-pie'}`}
                  aria-hidden="true"
                />
                {showMetricDetails
                  ? `Hide ${(openMetricCard.detailsToggle || 'point distribution').toLowerCase()}`
                  : openMetricCard.detailsToggle || 'Point distribution'}
              </button>
              {showMetricDetails ? (
                <>
                  <ul className="att-analytics-punct-modal-list">
                    {(openMetricCard.details || []).map((row) => (
                      <li key={`${openMetricCard.key}-${row.label}`}>
                        <span>{row.label}</span>
                        <strong>{busy ? '...' : row.value}</strong>
                      </li>
                    ))}
                  </ul>
                  {openMetricCard.note ? (
                    <p className="att-analytics-punct-modal-note">{openMetricCard.note}</p>
                  ) : null}
                </>
              ) : null}
            </div>
          </div>
        ) : null}

        <section className={`att-analytics-charts${isTeam ? ' att-analytics-charts--single' : ''}`}>
          {isTeam ? (
            <>
              <div className="att-analytics-chart-card att-analytics-chart-card--kpi">
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
                  <>
                    <div className="att-analytics-punct-strip" title={teamKpi.note || ''}>
                      <button
                        type="button"
                        className={`att-analytics-punct-item${openPunct === 'avg' ? ' is-open' : ''}`}
                        aria-expanded={openPunct === 'avg'}
                        onClick={() => setOpenPunct((cur) => (cur === 'avg' ? null : 'avg'))}
                      >
                        <span className="att-analytics-punct-icon att-analytics-punct-icon--avg" aria-hidden="true">
                          <i className="fas fa-chart-pie" />
                        </span>
                        <div className="att-analytics-punct-body">
                          <span className="att-analytics-punct-label">Avg attendance KPI</span>
                          <strong>{Number(teamKpi.average || 0).toFixed(0)}/100</strong>
                        </div>
                      </button>
                      <button
                        type="button"
                        className={`att-analytics-punct-item${openPunct === 'top' ? ' is-open' : ''}`}
                        aria-expanded={openPunct === 'top'}
                        onClick={() => setOpenPunct((cur) => (cur === 'top' ? null : 'top'))}
                      >
                        <span className="att-analytics-punct-icon att-analytics-punct-icon--top" aria-hidden="true">
                          <i className="fas fa-trophy" />
                        </span>
                        <div className="att-analytics-punct-body">
                          <span className="att-analytics-punct-label">Top attendance KPI</span>
                          <strong>
                            {teamKpi.top ? `${Number(teamKpi.top.score || 0).toFixed(0)}/100` : '-'}
                          </strong>
                        </div>
                      </button>
                      <button
                        type="button"
                        className={`att-analytics-punct-item${openPunct === 'missed' ? ' is-open' : ''}`}
                        aria-expanded={openPunct === 'missed'}
                        onClick={() => setOpenPunct((cur) => (cur === 'missed' ? null : 'missed'))}
                      >
                        <span className="att-analytics-punct-icon att-analytics-punct-icon--missed" aria-hidden="true">
                          <i className="fas fa-sign-out-alt" />
                        </span>
                        <div className="att-analytics-punct-body">
                          <span className="att-analytics-punct-label">Missed sign-outs</span>
                          <strong>{Number(teamPunct?.missedSignOuts || 0)}</strong>
                        </div>
                      </button>
                      <button
                        type="button"
                        className={`att-analytics-punct-item${openPunct === 'late' ? ' is-open' : ''}`}
                        aria-expanded={openPunct === 'late'}
                        onClick={() => setOpenPunct((cur) => (cur === 'late' ? null : 'late'))}
                      >
                        <span className="att-analytics-punct-icon att-analytics-punct-icon--late" aria-hidden="true">
                          <i className="fas fa-clock" />
                        </span>
                        <div className="att-analytics-punct-body">
                          <span className="att-analytics-punct-label">Late sign-ins</span>
                          <strong>{Number(teamPunct?.lateIns || 0)}</strong>
                        </div>
                      </button>
                    </div>
                    {openPunct ? (
                      <div className="att-analytics-punct-modal" role="dialog" aria-modal="true">
                        <button
                          type="button"
                          className="att-analytics-punct-modal-backdrop"
                          aria-label="Close details"
                          onClick={() => setOpenPunct(null)}
                        />
                        <div className="att-analytics-punct-modal-card">
                          <div className="att-analytics-punct-modal-head">
                            <h3 className="att-analytics-punct-modal-title">
                              {openPunct === 'avg'
                                ? 'Avg attendance KPI'
                                : openPunct === 'top'
                                  ? 'Top attendance KPI'
                                  : openPunct === 'missed'
                                    ? 'Missed sign-outs'
                                    : 'Late sign-ins'}
                            </h3>
                            <button
                              type="button"
                              className="att-analytics-punct-modal-close"
                              aria-label="Close"
                              onClick={() => setOpenPunct(null)}
                            >
                              <i className="fas fa-times" aria-hidden="true" />
                            </button>
                          </div>
                          {openPunct === 'avg' ? (
                            <>
                              <div className="att-analytics-punct-modal-value">
                                {Number(teamKpi.average || 0).toFixed(0)}
                                <span>/100</span>
                              </div>
                              <p className="att-analytics-punct-modal-lead">
                                Team average 100-pt attendance KPI for {monthTitle}.
                              </p>
                              <ul className="att-analytics-punct-modal-list">
                                <li>
                                  <span>Attendance</span>
                                  <strong>{Number(teamKpi.weights?.attendance || 40)} pts</strong>
                                </li>
                                <li>
                                  <span>Daily todos (target {Number(teamKpi.targets?.dailyTodos || 5)})</span>
                                  <strong>{Number(teamKpi.weights?.dailyTasks || 30)} pts</strong>
                                </li>
                                <li>
                                  <span>Weekly tasks (target {Number(teamKpi.targets?.weeklyTasks || 7)})</span>
                                  <strong>{Number(teamKpi.weights?.weeklyTasks || 30)} pts</strong>
                                </li>
                              </ul>
                            </>
                          ) : null}
                          {openPunct === 'top' ? (
                            <>
                              <div className="att-analytics-punct-modal-value">
                                {teamKpi.top ? Number(teamKpi.top.score || 0).toFixed(0) : '-'}
                                {teamKpi.top ? <span>/100</span> : null}
                              </div>
                              <p className="att-analytics-punct-modal-lead">
                                {teamKpi.top
                                  ? `${teamKpi.top.name} currently leads the team.`
                                  : 'No scored employees yet.'}
                              </p>
                              <ul className="att-analytics-punct-modal-list">
                                <li>
                                  <span>Employee</span>
                                  <strong>{teamKpi.top?.name || '-'}</strong>
                                </li>
                                <li>
                                  <span>Grade</span>
                                  <strong>{teamKpi.top?.grade || '-'}</strong>
                                </li>
                                <li>
                                  <span>Score</span>
                                  <strong>
                                    {teamKpi.top ? `${Number(teamKpi.top.score || 0).toFixed(0)}/100` : '-'}
                                  </strong>
                                </li>
                              </ul>
                            </>
                          ) : null}
                          {openPunct === 'missed' ? (
                            <>
                              <div className="att-analytics-punct-modal-value">
                                {Number(teamPunct?.missedSignOuts || 0)}
                              </div>
                              <p className="att-analytics-punct-modal-lead">
                                Forgotten clock-outs in the selected period.
                              </p>
                              <ul className="att-analytics-punct-modal-list">
                                <li>
                                  <span>Missed sign-outs</span>
                                  <strong>{Number(teamPunct?.missedSignOuts || 0)}</strong>
                                </li>
                                <li>
                                  <span>Penalty each</span>
                                  <strong>-{Number(teamPunct?.rules?.missedOutPenalty || 40)}</strong>
                                </li>
                                <li>
                                  <span>Late sign-ins (related)</span>
                                  <strong>{Number(teamPunct?.lateIns || 0)}</strong>
                                </li>
                              </ul>
                              <p className="att-analytics-punct-modal-note">
                                {teamPunct?.rules?.note ||
                                  'Open sessions today are not penalized until clock-out.'}
                              </p>
                            </>
                          ) : null}
                          {openPunct === 'late' ? (
                            <>
                              <div className="att-analytics-punct-modal-value">
                                {Number(teamPunct?.lateIns || 0)}
                              </div>
                              <p className="att-analytics-punct-modal-lead">
                                Late arrivals counted in the selected period.
                              </p>
                              <ul className="att-analytics-punct-modal-list">
                                <li>
                                  <span>Late sign-ins</span>
                                  <strong>{Number(teamPunct?.lateIns || 0)}</strong>
                                </li>
                                <li>
                                  <span>Penalty each</span>
                                  <strong>-{Number(teamPunct?.rules?.lateInPenalty || 30)}</strong>
                                </li>
                                <li>
                                  <span>Avg sign-in score</span>
                                  <strong>
                                    {teamPunct?.averageSignIn != null
                                      ? `${Number(teamPunct.averageSignIn).toFixed(0)}%`
                                      : '-'}
                                  </strong>
                                </li>
                              </ul>
                              <p className="att-analytics-punct-modal-note">
                                {teamPunct?.rules?.note ||
                                  'Late sign-in reduces the attendance portion of the KPI.'}
                              </p>
                            </>
                          ) : null}
                        </div>
                      </div>
                    ) : null}
                  </>
                ) : null}
              </div>
              <div className="att-analytics-chart-card">
                <div className="att-analytics-chart-head">
                  <div>
                    <h2 className="att-analytics-chart-title">
                      {barMetric === 'points' ? 'Team KPI chart' : 'Team hours chart'}
                    </h2>
                    <p className="att-analytics-chart-sub">
                      {barMetric === 'points'
                        ? `100-pt KPI scores for ${barRangeLabel}`
                        : `Hours worked ${barRangeLabel}`}
                    </p>
                  </div>
                  <div className="att-analytics-chart-controls">
                    <div className="att-analytics-periods att-analytics-periods--blue" role="tablist" aria-label="Chart metric">
                      {[
                        { value: 'hours', label: 'Hours' },
                        { value: 'points', label: 'Points' },
                      ].map((opt) => (
                        <button
                          key={opt.value}
                          type="button"
                          role="tab"
                          aria-selected={barMetric === opt.value}
                          className={`att-analytics-period${barMetric === opt.value ? ' is-active' : ''}`}
                          onClick={() => setBarMetric(opt.value)}
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
                  periodLabel={barRangeLabel}
                  metric={barMetric}
                />
              </div>
            </>
          ) : (
            <>
              {personalKpi ? (
                <div className="att-analytics-chart-card att-analytics-chart-card--kpi att-analytics-chart-card--personal-kpi">
                  <div className="att-analytics-chart-title-row">
                    <h2 className="att-analytics-chart-title">My attendance KPI points</h2>
                    <div className="att-analytics-about-wrap" ref={kpiAboutRef}>
                      <button
                        type="button"
                        className={`att-analytics-about-btn${showKpiAbout ? ' is-active' : ''}`}
                        aria-label="About my attendance KPI points"
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
                            Attendance 40 + Daily todos (target 5) 30 + Weekly tasks (target 7) 30 = 100
                          </div>
                        </>
                      ) : null}
                    </div>
                  </div>
                  <div className="att-analytics-punct-strip att-analytics-punct-strip--personal">
                    <button
                      type="button"
                      className={`att-analytics-punct-item${openPunct === 'p-total' ? ' is-open' : ''}`}
                      aria-expanded={openPunct === 'p-total'}
                      onClick={() => setOpenPunct((cur) => (cur === 'p-total' ? null : 'p-total'))}
                    >
                      <span className="att-analytics-punct-icon att-analytics-punct-icon--avg" aria-hidden="true">
                        <i className="fas fa-star" />
                      </span>
                      <div className="att-analytics-punct-body">
                        <span className="att-analytics-punct-label">Total</span>
                        <strong>{Number(personalKpi.kpiPoints || 0).toFixed(0)}/100</strong>
                      </div>
                    </button>
                    <button
                      type="button"
                      className={`att-analytics-punct-item${openPunct === 'p-att' ? ' is-open' : ''}`}
                      aria-expanded={openPunct === 'p-att'}
                      onClick={() => {
                        setShowAttDistribution(false);
                        setShowDailyTaskList(false);
                        setShowWeeklyTaskList(false);
                        setOpenPunct((cur) => (cur === 'p-att' ? null : 'p-att'));
                      }}
                    >
                      <span className="att-analytics-punct-icon att-analytics-punct-icon--top" aria-hidden="true">
                        <i className="fas fa-user-check" />
                      </span>
                      <div className="att-analytics-punct-body">
                        <span className="att-analytics-punct-label">Attendance</span>
                        <strong>{Number(personalKpi.kpiBreakdown?.attendance || 0).toFixed(0)}/40</strong>
                      </div>
                    </button>
                    <button
                      type="button"
                      className={`att-analytics-punct-item${openPunct === 'p-daily' ? ' is-open' : ''}`}
                      aria-expanded={openPunct === 'p-daily'}
                      onClick={() => {
                        setShowAttDistribution(false);
                        setShowDailyTaskList(false);
                        setShowWeeklyTaskList(false);
                        setOpenPunct((cur) => (cur === 'p-daily' ? null : 'p-daily'));
                      }}
                    >
                      <span className="att-analytics-punct-icon att-analytics-punct-icon--late" aria-hidden="true">
                        <i className="fas fa-tasks" />
                      </span>
                      <div className="att-analytics-punct-body">
                        <span className="att-analytics-punct-label">Daily tasks</span>
                        <strong>{Number(personalKpi.kpiBreakdown?.dailyTasks || 0).toFixed(0)}/30</strong>
                      </div>
                    </button>
                    <button
                      type="button"
                      className={`att-analytics-punct-item${openPunct === 'p-weekly' ? ' is-open' : ''}`}
                      aria-expanded={openPunct === 'p-weekly'}
                      onClick={() => {
                        setShowAttDistribution(false);
                        setShowDailyTaskList(false);
                        setShowWeeklyTaskList(false);
                        setOpenPunct((cur) => (cur === 'p-weekly' ? null : 'p-weekly'));
                      }}
                    >
                      <span className="att-analytics-punct-icon att-analytics-punct-icon--missed" aria-hidden="true">
                        <i className="fas fa-calendar-week" />
                      </span>
                      <div className="att-analytics-punct-body">
                        <span className="att-analytics-punct-label">Weekly tasks</span>
                        <strong>{Number(personalKpi.kpiBreakdown?.weeklyTasks || 0).toFixed(0)}/30</strong>
                      </div>
                    </button>
                  </div>
                  {openPunct && String(openPunct).startsWith('p-') ? (
                    <div className="att-analytics-punct-modal" role="dialog" aria-modal="true">
                      <button
                        type="button"
                        className="att-analytics-punct-modal-backdrop"
                        aria-label="Close details"
                        onClick={() => {
                          setShowAttDistribution(false);
                          setShowDailyTaskList(false);
                          setShowWeeklyTaskList(false);
                          setOpenPunct(null);
                        }}
                      />
                      <div className="att-analytics-punct-modal-card">
                        <div className="att-analytics-punct-modal-head">
                          <h3 className="att-analytics-punct-modal-title">
                            {openPunct === 'p-total'
                              ? 'Total KPI'
                              : openPunct === 'p-att'
                                ? 'Attendance'
                                : openPunct === 'p-daily'
                                  ? 'Daily tasks'
                                  : 'Weekly tasks'}
                          </h3>
                          <button
                            type="button"
                            className="att-analytics-punct-modal-close"
                            aria-label="Close"
                            onClick={() => {
                              setShowAttDistribution(false);
                              setShowDailyTaskList(false);
                              setShowWeeklyTaskList(false);
                              setOpenPunct(null);
                            }}
                          >
                            <i className="fas fa-times" aria-hidden="true" />
                          </button>
                        </div>
                        {openPunct === 'p-total' ? (
                          <>
                            <div className="att-analytics-punct-modal-value">
                              {Number(personalKpi.kpiPoints || 0).toFixed(0)}
                              <span>/100</span>
                            </div>
                            <p className="att-analytics-punct-modal-lead">
                              Your combined attendance KPI for this period.
                            </p>
                            <ul className="att-analytics-punct-modal-list">
                              <li>
                                <span>Grade</span>
                                <strong>{personalKpi.grade || '-'}</strong>
                              </li>
                              <li>
                                <span>Attendance</span>
                                <strong>
                                  {Number(personalKpi.kpiBreakdown?.attendance || 0).toFixed(0)}/40
                                </strong>
                              </li>
                              <li>
                                <span>Daily tasks</span>
                                <strong>
                                  {Number(personalKpi.kpiBreakdown?.dailyTasks || 0).toFixed(0)}/30
                                </strong>
                              </li>
                              <li>
                                <span>Weekly tasks</span>
                                <strong>
                                  {Number(personalKpi.kpiBreakdown?.weeklyTasks || 0).toFixed(0)}/30
                                </strong>
                              </li>
                            </ul>
                          </>
                        ) : null}
                        {openPunct === 'p-att' ? (
                          <>
                            <div className="att-analytics-punct-modal-value">
                              {Number(personalKpi.kpiBreakdown?.attendance || 0).toFixed(0)}
                              <span>/40</span>
                            </div>
                            <p className="att-analytics-punct-modal-lead">
                              Sign-in / sign-out attendance score.
                            </p>
                            <ul className="att-analytics-punct-modal-list">
                              <li>
                                <span>Attendance points</span>
                                <strong>
                                  {Number(personalKpi.kpiBreakdown?.attendance || 0).toFixed(0)}/40
                                </strong>
                              </li>
                              <li>
                                <span>Weight</span>
                                <strong>40 of 100</strong>
                              </li>
                            </ul>
                            <button
                              type="button"
                              className="att-analytics-punct-modal-action"
                              onClick={() => setShowAttDistribution((v) => !v)}
                            >
                              <i
                                className={`fas ${showAttDistribution ? 'fa-chevron-up' : 'fa-chart-pie'}`}
                                aria-hidden="true"
                              />
                              {showAttDistribution ? 'Hide point distribution' : 'Point distribution'}
                            </button>
                            {showAttDistribution ? (
                              <>
                                <ul className="att-analytics-punct-modal-list">
                                  <li>
                                    <span>Sign-in</span>
                                    <strong>
                                      {Number(personalKpi.attendanceDetail?.signInPoints || 0).toFixed(0)}/20
                                    </strong>
                                  </li>
                                  <li>
                                    <span>Sign-out</span>
                                    <strong>
                                      {Number(personalKpi.attendanceDetail?.signOutPoints || 0).toFixed(0)}/20
                                    </strong>
                                  </li>
                                  <li>
                                    <span>Punctuality</span>
                                    <strong>
                                      {Number(personalKpi.attendanceDetail?.punctualityScore || 0).toFixed(0)}%
                                    </strong>
                                  </li>
                                  <li>
                                    <span>Late sign-ins</span>
                                    <strong>{Number(personalKpi.attendanceDetail?.lateIns || 0)}</strong>
                                  </li>
                                  <li>
                                    <span>Missed sign-outs</span>
                                    <strong>{Number(personalKpi.attendanceDetail?.missedOuts || 0)}</strong>
                                  </li>
                                </ul>
                                <p className="att-analytics-punct-modal-note">
                                  {personalKpi.attendanceDetail?.rules?.note ||
                                    'Attendance 40 = sign-in (20) + sign-out (20) from period averages.'}
                                </p>
                              </>
                            ) : null}
                          </>
                        ) : null}
                        {openPunct === 'p-daily' ? (
                          <>
                            <div className="att-analytics-punct-modal-value">
                              {Number(personalKpi.kpiBreakdown?.dailyTasks || 0).toFixed(0)}
                              <span>/30</span>
                            </div>
                            <p className="att-analytics-punct-modal-lead">
                              Daily todos toward the period target.
                            </p>
                            <ul className="att-analytics-punct-modal-list">
                              <li>
                                <span>Completed</span>
                                <strong>
                                  {Number(personalKpi.kpiBreakdown?.dailyCompleted || 0)}
                                </strong>
                              </li>
                              <li>
                                <span>Points</span>
                                <strong>
                                  {Number(personalKpi.kpiBreakdown?.dailyTasks || 0).toFixed(0)}/30
                                </strong>
                              </li>
                              <li>
                                <span>Target</span>
                                <strong>5 per weekday</strong>
                              </li>
                            </ul>
                            <button
                              type="button"
                              className="att-analytics-punct-modal-action"
                              onClick={() => setShowDailyTaskList((v) => !v)}
                            >
                              <i
                                className={`fas ${showDailyTaskList ? 'fa-chevron-up' : 'fa-list-ul'}`}
                                aria-hidden="true"
                              />
                              {showDailyTaskList ? 'Hide daily tasks' : 'View daily tasks'}
                            </button>
                            {showDailyTaskList ? (
                              <div className="att-analytics-task-list">
                                {(personalKpi.dailyTasksList || []).length === 0 ? (
                                  <p className="att-analytics-task-empty">No daily tasks found for this period.</p>
                                ) : (
                                  <ul>
                                    {(personalKpi.dailyTasksList || []).map((task) => (
                                      <li
                                        key={`${task.source || 'task'}-${task.id || task.title}`}
                                        className={task.completed ? 'is-done' : ''}
                                      >
                                        <span
                                          className={`att-analytics-task-check${task.completed ? ' is-done' : ''}`}
                                          aria-hidden="true"
                                        >
                                          <i className={`fas ${task.completed ? 'fa-check' : 'fa-circle'}`} />
                                        </span>
                                        <span className="att-analytics-task-title">{task.title}</span>
                                      </li>
                                    ))}
                                  </ul>
                                )}
                              </div>
                            ) : null}
                          </>
                        ) : null}
                        {openPunct === 'p-weekly' ? (
                          <>
                            <div className="att-analytics-punct-modal-value">
                              {Number(personalKpi.kpiBreakdown?.weeklyTasks || 0).toFixed(0)}
                              <span>/30</span>
                            </div>
                            <p className="att-analytics-punct-modal-lead">
                              Weekly tasks completed in this period.
                            </p>
                            <ul className="att-analytics-punct-modal-list">
                              <li>
                                <span>Completed</span>
                                <strong>
                                  {Number(personalKpi.kpiBreakdown?.weeklyCompleted || 0)}/
                                  {Number(personalKpi.kpiBreakdown?.weeklyTotal || 0)}
                                </strong>
                              </li>
                              <li>
                                <span>Points</span>
                                <strong>
                                  {Number(personalKpi.kpiBreakdown?.weeklyTasks || 0).toFixed(0)}/30
                                </strong>
                              </li>
                              <li>
                                <span>Target</span>
                                <strong>7 per week</strong>
                              </li>
                            </ul>
                            <button
                              type="button"
                              className="att-analytics-punct-modal-action"
                              onClick={() => setShowWeeklyTaskList((v) => !v)}
                            >
                              <i
                                className={`fas ${showWeeklyTaskList ? 'fa-chevron-up' : 'fa-list-ul'}`}
                                aria-hidden="true"
                              />
                              {showWeeklyTaskList ? 'Hide weekly tasks' : 'View weekly tasks'}
                            </button>
                            {showWeeklyTaskList ? (
                              <div className="att-analytics-task-list">
                                {(personalKpi.weeklyTasksList || []).length === 0 ? (
                                  <p className="att-analytics-task-empty">No weekly tasks found for this period.</p>
                                ) : (
                                  <ul>
                                    {(personalKpi.weeklyTasksList || []).map((task) => (
                                      <li
                                        key={`${task.source || 'task'}-${task.id || task.title}`}
                                        className={task.completed ? 'is-done' : ''}
                                      >
                                        <span
                                          className={`att-analytics-task-check${task.completed ? ' is-done' : ''}`}
                                          aria-hidden="true"
                                        >
                                          <i className={`fas ${task.completed ? 'fa-check' : 'fa-circle'}`} />
                                        </span>
                                        <span className="att-analytics-task-title">{task.title}</span>
                                      </li>
                                    ))}
                                  </ul>
                                )}
                              </div>
                            ) : null}
                          </>
                        ) : null}
                      </div>
                    </div>
                  ) : null}
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
      </div>
    </div>
  );
}
