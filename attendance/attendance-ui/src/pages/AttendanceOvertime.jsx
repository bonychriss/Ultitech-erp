import React, { useEffect, useMemo, useState } from 'react';
import { postAttendanceAction } from '../api';
import '../attendance-analytics.css';

function formatDate(value) {
  if (!value) return '-';
  const d = new Date(`${value}T12:00:00`);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatShortDate(value) {
  if (!value) return '-';
  const d = new Date(`${value}T12:00:00`);
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
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

function OtBarChart({ labels = [], values = [], color = '#ea580c', emptyLabel = 'No overtime in this period.' }) {
  const nums = values.map((v) => Number(v) || 0);
  const max = Math.max(...nums, 0.1);
  const hasAny = nums.some((v) => v > 0);
  if (!labels.length || !hasAny) {
    return <div className="att-analytics-chart-empty">{emptyLabel}</div>;
  }
  return (
    <div className="att-analytics-bars" role="img" aria-label="Overtime chart">
      {labels.map((label, idx) => {
        const value = nums[idx] || 0;
        const height = value > 0 ? Math.max(6, Math.round((value / max) * 100)) : 2;
        const shortLabel =
          String(label).length > 6
            ? formatShortDate(label)
            : label;
        return (
          <div className="att-analytics-bar-col" key={`${label}-${idx}`}>
            <div className="att-analytics-bar-value">{value ? value.toFixed(1) : ''}</div>
            <div className="att-analytics-bar-track">
              <div
                className="att-analytics-bar-fill"
                style={{
                  height: `${height}%`,
                  background: value > 0 ? color : 'rgba(148, 163, 184, 0.25)',
                }}
                title={`${label}: ${value}h OT`}
              />
            </div>
            <div className="att-analytics-bar-label">{shortLabel}</div>
          </div>
        );
      })}
    </div>
  );
}

function TeamOtBarChart({ employees = [], periodLabel = '' }) {
  const width = 760;
  const labelSpace = 118;
  const height = 280 + labelSpace;
  const pad = { top: 28, right: 16, bottom: labelSpace, left: 40 };
  const plotW = width - pad.left - pad.right;
  const plotH = height - pad.top - pad.bottom;
  const sorted = [...employees].sort((a, b) => Number(b.ot || 0) - Number(a.ot || 0));
  const count = sorted.length;
  const max = Math.max(...sorted.map((e) => Number(e.ot) || 0), 0.1);

  if (!count) {
    return <div className="att-analytics-chart-empty">No team overtime in this period.</div>;
  }

  const gap = Math.min(18, Math.max(6, plotW / (count * 4)));
  const barW = Math.min(48, (plotW - gap * (count + 1)) / count);

  return (
    <svg
      className="att-analytics-team-svg"
      viewBox={`0 0 ${width} ${height}`}
      role="img"
      aria-label={`Team overtime${periodLabel ? ` ${periodLabel}` : ''}`}
    >
      {[0.25, 0.5, 0.75, 1].map((t) => {
        const y = pad.top + plotH * (1 - t);
        return (
          <g key={t}>
            <line x1={pad.left} x2={width - pad.right} y1={y} y2={y} stroke="rgba(148,163,184,0.35)" strokeWidth="1" />
            <text x={pad.left - 8} y={y + 4} textAnchor="end" fontSize="10" fill="#64748b">
              {(max * t).toFixed(1)}
            </text>
          </g>
        );
      })}
      {sorted.map((emp, i) => {
        const ot = Number(emp.ot) || 0;
        const h = ot > 0 ? Math.max(4, (ot / max) * plotH) : 2;
        const x = pad.left + gap + i * (barW + gap);
        const y = pad.top + plotH - h;
        return (
          <g key={emp.id || i}>
            <rect
              x={x}
              y={y}
              width={barW}
              height={h}
              rx="6"
              fill={ot > 0 ? emp.color || '#ea580c' : 'rgba(148,163,184,0.25)'}
            >
              <title>{`${emp.name}: ${ot.toFixed(1)}h OT`}</title>
            </rect>
            {ot > 0 ? (
              <text x={x + barW / 2} y={y - 6} textAnchor="middle" fontSize="11" fontWeight="600" fill="#0f172a">
                {ot.toFixed(1)}
              </text>
            ) : null}
            <text
              x={x + barW / 2}
              y={pad.top + plotH + 16}
              textAnchor="middle"
              fontSize="10"
              fill="#64748b"
            >
              {emp.initials}
            </text>
            <text
              x={x + barW / 2}
              y={pad.top + plotH + 32}
              textAnchor="middle"
              fontSize="9"
              fill="#94a3b8"
            >
              {(emp.name || '').split(/\s+/)[0] || ''}
            </text>
          </g>
        );
      })}
    </svg>
  );
}

function aggregateTeamOt(series = [], labels = [], fromDate = '', toDate = '') {
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
    const values = s.otValues || s.values || [];
    let total = 0;
    for (let i = startIdx; i <= endIdx; i += 1) {
      total += Number(values[i] || 0);
    }
    return {
      id: s.id,
      name: s.name,
      color: s.color || '#ea580c',
      ot: Math.round(total * 10) / 10,
      initials: initialsFromName(s.name),
    };
  });
}

export default function AttendanceOvertime({ data: initial = {} }) {
  const [state, setState] = useState({
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
    charts: initial.charts || {},
    range: initial.range || {},
    rangeBounds: initial.rangeBounds || {},
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [barFrom, setBarFrom] = useState('');
  const [barTo, setBarTo] = useState('');

  const isTeam = state.scope === 'team';
  const metrics = state.metrics || {};
  const dailyOt = state.charts?.dailyOt || { labels: [], values: [] };
  const weeklyOt = state.charts?.weeklyOt || { labels: [], values: [] };
  const teamLine = state.charts?.teamLine || { labels: [], series: [] };
  const chartMinDate = state.rangeBounds?.min || state.range?.start || '';
  const chartMaxDate = state.rangeBounds?.max || state.range?.end || '';
  const statsUrl = initial.links?.stats || '';

  useEffect(() => {
    if (!isTeam) return;
    const start = state.range?.start || '';
    const end = state.range?.end || '';
    if (start) setBarFrom(start);
    if (end) setBarTo(end);
  }, [isTeam, state.range?.start, state.range?.end]);

  const teamEmployees = useMemo(
    () => aggregateTeamOt(teamLine.series || [], teamLine.labels || [], barFrom, barTo),
    [teamLine.series, teamLine.labels, barFrom, barTo]
  );

  const rangeLabel = useMemo(() => {
    if (isTeam && (barFrom || barTo)) {
      return `${formatDate(barFrom || chartMinDate)} - ${formatDate(barTo || chartMaxDate)}`;
    }
    if (state.range?.start && state.range?.end) {
      return `${formatDate(state.range.start)} - ${formatDate(state.range.end)}`;
    }
    return 'Selected period';
  }, [isTeam, barFrom, barTo, chartMinDate, chartMaxDate, state.range]);

  async function loadOvertime({ period, scope, start, end } = {}) {
    const nextPeriod = Number(period ?? state.period);
    const nextScope = (scope ?? state.scope) === 'team' ? 'team' : 'personal';
    const hasExplicitRange = start !== undefined && end !== undefined;
    const nextStart = hasExplicitRange ? String(start || '') : '';
    const nextEnd = hasExplicitRange ? String(end || '') : '';
    const usingCustomRange = nextScope === 'team' && hasExplicitRange && nextStart !== '' && nextEnd !== '';

    setBusy(true);
    setError('');
    try {
      const payload = await postAttendanceAction(
        {
          action: 'analytics',
          period: nextPeriod,
          scope: nextScope,
          ...(usingCustomRange ? { start: nextStart, end: nextEnd } : {}),
        },
        initial.apiUrl
      );
      setState((prev) => ({
        ...prev,
        period: Number(payload.period || nextPeriod),
        scope: payload.scope === 'team' ? 'team' : 'personal',
        scopeOptions: payload.scopeOptions || prev.scopeOptions,
        periodOptions: payload.periodOptions || prev.periodOptions,
        metrics: payload.metrics || {},
        charts: payload.charts || {},
        range: payload.range || {},
        rangeBounds: payload.rangeBounds || prev.rangeBounds,
      }));
    } catch (err) {
      setError(err?.message || 'Could not load overtime data.');
    } finally {
      setBusy(false);
    }
  }

  const onBarFromChange = (next) => {
    setBarFrom(next);
    if (next && barTo) {
      loadOvertime({ scope: 'team', start: next, end: barTo });
    }
  };

  const onBarToChange = (next) => {
    setBarTo(next);
    if (next && barFrom) {
      loadOvertime({ scope: 'team', start: barFrom, end: next });
    }
  };

  const summaryCards = [
    {
      key: 'total',
      label: 'Total overtime',
      value: `${Number(metrics.totalOt || 0)}h`,
      icon: 'fa-business-time',
      tone: 'amber',
    },
    {
      key: 'days',
      label: 'OT days',
      value: String(Number(metrics.otDays || 0)),
      icon: 'fa-calendar-day',
      tone: 'violet',
    },
    {
      key: 'avg',
      label: 'Avg OT / OT day',
      value: `${Number(metrics.avgOtPerDay || 0)}h`,
      icon: 'fa-chart-bar',
      tone: 'sky',
    },
  ];

  return (
    <div className="att-shell att-page-analytics att-page-overtime">
      <div className="att-analytics">
        <div className="att-analytics-header">
          <div className="att-analytics-header-range">
            {isTeam ? (
              <div className="att-analytics-date-range" aria-label="Overtime date range">
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
              <p className="att-analytics-sub att-analytics-sub--solo">{rangeLabel}</p>
            )}
          </div>
          <div className="att-analytics-header-controls">
            {statsUrl ? (
              <a className="att-analytics-period att-overtime-back" href={statsUrl}>
                Stats
              </a>
            ) : null}
            <div className="att-analytics-periods" role="tablist" aria-label="Overtime scope">
              {(state.scopeOptions || []).map((opt) => (
                <button
                  key={opt.value}
                  type="button"
                  role="tab"
                  aria-selected={state.scope === opt.value}
                  className={`att-analytics-period${state.scope === opt.value ? ' is-active' : ''}`}
                  disabled={busy}
                  onClick={() => loadOvertime({ scope: opt.value })}
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
                    onClick={() => loadOvertime({ period: opt.value })}
                  >
                    {opt.label}
                  </button>
                ))}
              </div>
            ) : null}
          </div>
        </div>

        {error ? <div className="att-desk-error">{error}</div> : null}

        <section className="att-analytics-kpi-grid" aria-label="Overtime summary">
          {summaryCards.map((card) => (
            <div key={card.key} className={`att-analytics-kpi att-analytics-kpi--chip att-analytics-kpi--${card.tone}`}>
              <span className="att-analytics-kpi-icon" aria-hidden="true">
                <i className={`fas ${card.icon}`} />
              </span>
              <span className="att-analytics-kpi-text">
                <span className="att-analytics-kpi-label">{card.label}</span>
                <span className="att-analytics-kpi-simple">{busy ? '...' : card.value}</span>
              </span>
            </div>
          ))}
        </section>

        <div className="att-analytics-charts">
          {isTeam ? (
            <div className="att-analytics-chart-card att-analytics-chart-card--wide">
              <div className="att-analytics-chart-head">
                <h2 className="att-analytics-chart-title">Team overtime</h2>
                <p className="att-analytics-chart-sub">{rangeLabel}</p>
              </div>
              <TeamOtBarChart employees={teamEmployees} periodLabel={rangeLabel} />
            </div>
          ) : (
            <>
              <div className="att-analytics-chart-card">
                <div className="att-analytics-chart-head">
                  <h2 className="att-analytics-chart-title">Daily overtime</h2>
                  <p className="att-analytics-chart-sub">Hours past end time by day</p>
                </div>
                <OtBarChart
                  labels={dailyOt.labels || []}
                  values={dailyOt.values || []}
                  color="#ea580c"
                />
              </div>
              <div className="att-analytics-chart-card">
                <div className="att-analytics-chart-head">
                  <h2 className="att-analytics-chart-title">Overtime by weekday</h2>
                  <p className="att-analytics-chart-sub">Totals for this period</p>
                </div>
                <OtBarChart
                  labels={weeklyOt.labels || []}
                  values={weeklyOt.values || []}
                  color="#c2410c"
                  emptyLabel="No weekday overtime in this period."
                />
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
