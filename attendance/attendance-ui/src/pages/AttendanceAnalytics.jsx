import React, { useMemo, useState } from 'react';
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

export default function AttendanceAnalytics({ data }) {
  const initial = data || {};
  const [state, setState] = useState(() => ({
    period: Number(initial.period || 30),
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
  }));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const metrics = state.metrics || {};
  const daily = state.charts?.daily || { labels: [], values: [] };
  const weekly = state.charts?.weekly || { labels: [], values: [] };

  const metricCards = useMemo(
    () => [
      {
        key: 'rate',
        label: 'Attendance rate',
        value: `${Number(metrics.attendanceRate || 0)}%`,
        hint: `${Number(metrics.presentDays || 0)} of ${Number(metrics.workingDays || 0)} days`,
        icon: 'fa-chart-line',
        tone: 'violet',
      },
      {
        key: 'punctual',
        label: 'Punctuality',
        value: `${Number(metrics.punctualityScore || 0)}%`,
        hint: `${Number(metrics.lateDays || 0)} late arrivals`,
        icon: 'fa-clock',
        tone: 'green',
      },
      {
        key: 'streak',
        label: 'Longest streak',
        value: String(Number(metrics.longestStreak || 0)),
        hint: `Current: ${Number(metrics.currentStreak || 0)} days`,
        icon: 'fa-fire',
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
    ],
    [metrics]
  );

  async function loadPeriod(period) {
    const next = Number(period);
    if (!next || next === state.period) return;
    setBusy(true);
    setError('');
    try {
      const result = await postAttendanceAction({
        action: 'analytics',
        period: next,
      });
      if (!result.success) {
        setError(result.message || 'Failed to load analytics.');
        return;
      }
      const payload = result.data || {};
      setState((cur) => ({
        ...cur,
        period: Number(payload.period || next),
        periodOptions: payload.periodOptions || cur.periodOptions,
        metrics: payload.metrics || {},
        charts: payload.charts || cur.charts,
        insights: payload.insights || [],
        history: payload.history || [],
        range: payload.range || {},
      }));
      const url = new URL(window.location.href);
      url.searchParams.set('period', String(next));
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
            <h1 className="att-analytics-title">Stats</h1>
            <p className="att-analytics-sub">
              {state.range?.start && state.range?.end
                ? `${formatDate(state.range.start)} - ${formatDate(state.range.end)}`
                : 'Your attendance performance'}
            </p>
          </div>
          <div className="att-analytics-periods" role="tablist" aria-label="Period">
            {(state.periodOptions || []).map((opt) => (
              <button
                key={opt.value}
                type="button"
                role="tab"
                aria-selected={Number(state.period) === Number(opt.value)}
                className={`att-analytics-period${Number(state.period) === Number(opt.value) ? ' is-active' : ''}`}
                disabled={busy}
                onClick={() => loadPeriod(opt.value)}
              >
                {opt.label}
              </button>
            ))}
          </div>
        </div>

        {error ? <div className="att-desk-error">{error}</div> : null}

        <section className="att-analytics-kpi-grid" aria-label="Summary">
          {metricCards.map((card) => (
            <div className={`att-analytics-kpi att-analytics-kpi--${card.tone}`} key={card.key}>
              <div className="att-analytics-kpi-icon" aria-hidden="true">
                <i className={`fas ${card.icon}`} />
              </div>
              <div>
                <div className="att-analytics-kpi-label">{card.label}</div>
                <div className="att-analytics-kpi-value">{busy ? '...' : card.value}</div>
                <div className="att-analytics-kpi-hint">{card.hint}</div>
              </div>
            </div>
          ))}
        </section>

        <section className="att-analytics-charts">
          <div className="att-analytics-chart-card">
            <h2 className="att-analytics-chart-title">Daily hours worked</h2>
            <BarChart labels={daily.labels || []} values={daily.values || []} color="#0284c7" />
          </div>
          <div className="att-analytics-chart-card">
            <h2 className="att-analytics-chart-title">Weekly distribution</h2>
            <BarChart labels={weekly.labels || []} values={weekly.values || []} color="#ea580c" />
          </div>
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
              Recent records
            </h2>
            <span className="att-desk-results-count">
              {busy ? 'Loading...' : `${(state.history || []).length} record${(state.history || []).length === 1 ? '' : 's'}`}
            </span>
          </div>
          <div className="att-desk-table-wrap">
            <table className="att-desk-table att-desk-table--activity">
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
                {(state.history || []).length === 0 ? (
                  <tr>
                    <td colSpan={6}>
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
                    <tr key={`${row.date}-${row.time_in || ''}`}>
                      <td>
                        <strong>{formatDate(row.date)}</strong>
                      </td>
                      <td>
                        <span className={`att-desk-status ${statusClass(row.status)}`}>{row.status || '-'}</span>
                      </td>
                      <td>{formatTime(row.time_in) || <span className="att-desk-muted">--:--</span>}</td>
                      <td>
                        {formatTime(row.time_out) || <span className="att-desk-muted">--:--</span>}
                      </td>
                      <td>{row.total_hours != null ? `${row.total_hours}h` : '-'}</td>
                      <td>
                        {row.overtime_hours && Number(row.overtime_hours) > 0
                          ? `+${row.overtime_hours}`
                          : <span className="att-desk-muted">--</span>}
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
