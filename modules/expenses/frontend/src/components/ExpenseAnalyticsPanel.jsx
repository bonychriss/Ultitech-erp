import { useEffect, useId, useMemo, useRef, useState } from 'react';
import {
  Area,
  AreaChart,
  Cell,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';

const EMPTY_TREND = Array.from({ length: 7 }, (_, i) => ({
  date: `d${i}`,
  label: '',
  amount: 0,
}));

function formatMoney(value) {
  const amount = Number(value) || 0;
  return `TZS ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatCompactMoney(value) {
  const amount = Number(value) || 0;
  if (amount >= 1_000_000) {
    return `TZS ${(amount / 1_000_000).toLocaleString('en-US', { maximumFractionDigits: 2 })}M`;
  }
  if (amount >= 1_000) {
    return `TZS ${(amount / 1_000).toLocaleString('en-US', { maximumFractionDigits: 1 })}k`;
  }
  return formatMoney(amount);
}

function formatTrendRange(from, to) {
  if (!from || !to) return '';
  const a = new Date(`${from}T12:00:00`);
  const b = new Date(`${to}T12:00:00`);
  const opts = { month: 'short', day: 'numeric', year: 'numeric' };
  return `${a.toLocaleDateString('en-US', opts)} - ${b.toLocaleDateString('en-US', opts)}`;
}

function chartPointLabel(label) {
  const text = String(label || '').trim();
  return text || 'this period';
}

function classifyStep(prevAmount, currAmount) {
  const base = Number(prevAmount) || 0;
  const curr = Number(currAmount) || 0;
  const diffPct = base > 0 ? ((curr - base) / base) * 100 : (curr > 0 ? 100 : 0);
  if (diffPct > 5) return 'up';
  if (diffPct < -5) return 'down';
  return 'same';
}

/**
 * Plain-English help copy from the chart points and selected From?To range.
 * Uses the chart's own labels so it works for days, weeks, months, or years.
 */
function buildTrendHelpExplanation({ trendData, rangeLabel, changePct }) {
  const points = (Array.isArray(trendData) ? trendData : [])
    .map((row) => ({
      label: chartPointLabel(row.label),
      amount: Number(row.amount) || 0,
    }))
    .filter((row) => row.label);
  const range = rangeLabel || 'the selected date range';
  const hasSpend = points.some((row) => row.amount > 0);

  let overall;
  if (!hasSpend || points.length === 0) {
    overall = `For ${range}, the chart does not show any posted or paid expenses yet.`;
  } else if (points.length === 1) {
    overall = `For ${range}, the chart shows expenses for ${points[0].label}.`;
  } else {
    const parts = [];
    for (let i = 1; i < points.length; i += 1) {
      parts.push({
        from: points[i - 1].label,
        to: points[i].label,
        kind: classifyStep(points[i - 1].amount, points[i].amount),
      });
    }

    const fragments = [];
    let i = 0;
    while (i < parts.length) {
      const kind = parts[i].kind;
      let j = i;
      while (j + 1 < parts.length && parts[j + 1].kind === kind) j += 1;
      const first = parts[i];
      const last = parts[j];
      if (kind === 'up') {
        fragments.push(
          j > i
            ? `increased from ${first.from} through ${last.to}`
            : `increased from ${first.from} to ${first.to}`
        );
      } else if (kind === 'down') {
        fragments.push(
          j > i
            ? `decreased from ${first.from} through ${last.to}`
            : `decreased from ${first.from} to ${first.to}`
        );
      } else {
        fragments.push(
          j > i
            ? `stayed almost the same from ${first.from} through ${last.to}`
            : `stayed almost the same from ${first.from} to ${first.to}`
        );
      }
      i = j + 1;
    }

    if (fragments.length === 1) {
      overall = `For ${range}, expenses ${fragments[0]}.`;
    } else if (fragments.length === 2) {
      overall = `For ${range}, expenses ${fragments[0]}, then ${fragments[1]}.`;
    } else {
      overall = `For ${range}, expenses ${fragments.slice(0, -1).join(', ')}, then ${fragments[fragments.length - 1]}.`;
    }
  }

  let latestChange = null;
  if (changePct != null && points.length >= 2) {
    const lastLabel = points[points.length - 1].label;
    const prevLabel = points[points.length - 2].label;
    const absPct = Math.abs(changePct).toFixed(1);
    const signed = `${changePct >= 0 ? '+' : ''}${changePct.toFixed(1)}%`;
    if (changePct > 0) {
      latestChange = {
        label: signed,
        detail: `means expenses in ${lastLabel} were ${absPct}% higher than in ${prevLabel}.`,
      };
    } else if (changePct < 0) {
      latestChange = {
        label: signed,
        detail: `means expenses in ${lastLabel} were ${absPct}% lower than in ${prevLabel}.`,
      };
    } else {
      latestChange = {
        label: signed,
        detail: `means expenses in ${lastLabel} were about the same as in ${prevLabel}.`,
      };
    }
  }

  const reasons = latestChange
    ? 'This may be due to a change in spending or in how many expenses were recorded. The actual reason must be checked in the expense records.'
    : 'If you need to know why these amounts appear, check the expense records for the selected date range.';

  return { overall, latestChange, reasons };
}

function useIsDarkTheme() {
  const [isDark, setIsDark] = useState(() => (
    typeof document !== 'undefined'
      && document.documentElement.getAttribute('data-theme') === 'dark'
  ));

  useEffect(() => {
    const root = document.documentElement;
    const sync = () => setIsDark(root.getAttribute('data-theme') === 'dark');
    sync();
    const observer = new MutationObserver(sync);
    observer.observe(root, { attributes: true, attributeFilter: ['data-theme'] });
    return () => observer.disconnect();
  }, []);

  return isDark;
}

function TrendTooltip({ active, payload, label }) {
  if (!active || !payload?.length) return null;
  const amount = payload[0]?.value;
  return (
    <div className="exp-desk-trend-tooltip">
      <div className="exp-desk-trend-tooltip-label">{label || 'Period'}</div>
      <div className="exp-desk-trend-tooltip-value">{formatMoney(amount)}</div>
    </div>
  );
}

function LastPointDot(props) {
  const { cx, cy, index, dataLength, stroke } = props;
  if (cx == null || cy == null || index !== dataLength - 1) return null;
  return (
    <g className="exp-desk-trend-last-dot">
      <circle cx={cx} cy={cy} r={7} fill={stroke} opacity={0.22} />
      <circle cx={cx} cy={cy} r={3.5} fill="#fff" stroke={stroke} strokeWidth={2.5} />
    </g>
  );
}

export default function ExpenseAnalyticsPanel({
  trend = [],
  statusBreakdown = [],
  trendFrom = '',
  trendTo = '',
  totalRecords = 0,
}) {
  const gradId = useId().replace(/:/g, '');
  const helpId = useId().replace(/:/g, '');
  const isDark = useIsDarkTheme();
  const [helpOpen, setHelpOpen] = useState(false);
  const helpWrapRef = useRef(null);
  const trendData = Array.isArray(trend) && trend.length > 0 ? trend : EMPTY_TREND;
  const hasTrendSpend = trendData.some((row) => Number(row.amount) > 0);
  const breakdownTotal = statusBreakdown.reduce((sum, row) => sum + (Number(row.count) || 0), 0) || totalRecords;
  const pieData = statusBreakdown.map((row) => ({
    ...row,
    value: Number(row.count) || 0,
  }));

  const summary = useMemo(() => {
    const amounts = trendData.map((row) => Number(row.amount) || 0);
    const total = amounts.reduce((sum, n) => sum + n, 0);
    const last = amounts[amounts.length - 1] || 0;
    const prev = amounts.length > 1 ? amounts[amounts.length - 2] : 0;
    let changePct = null;
    if (prev > 0) {
      changePct = ((last - prev) / prev) * 100;
    } else if (last > 0 && prev === 0) {
      changePct = 100;
    } else if (last === 0 && prev === 0) {
      changePct = 0;
    }
    return { total, last, changePct };
  }, [trendData]);

  useEffect(() => {
    if (!helpOpen) return undefined;
    const onPointerDown = (event) => {
      if (helpWrapRef.current && !helpWrapRef.current.contains(event.target)) {
        setHelpOpen(false);
      }
    };
    const onKeyDown = (event) => {
      if (event.key === 'Escape') setHelpOpen(false);
    };
    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('pointerdown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [helpOpen]);

  const stroke = isDark ? '#38bdf8' : '#3b82f6';
  const tickFill = isDark ? '#94a3b8' : '#94a3b8';
  const changeUp = summary.changePct != null && summary.changePct >= 0;
  const changeLabel = summary.changePct == null
    ? '?'
    : `${changeUp ? '+' : ''}${summary.changePct.toFixed(1)}%`;
  const rangeLabel = formatTrendRange(trendFrom, trendTo);
  const helpCopy = useMemo(
    () => buildTrendHelpExplanation({
      trendData,
      rangeLabel,
      changePct: summary.changePct,
    }),
    [trendData, rangeLabel, summary.changePct]
  );

  return (
    <section className="exp-desk-analytics" aria-label="Expense analytics">
      <div className="exp-desk-analytics-card exp-desk-analytics-card--trend">
        <div className="exp-desk-analytics-head">
          <div>
            <h3 className="exp-desk-analytics-title">Expense Trend</h3>
            <p className="exp-desk-analytics-sub">Monthly expense amount</p>
          </div>
          <div className="exp-desk-analytics-range" title="Trend period">
            {formatTrendRange(trendFrom, trendTo) || 'This month'}
          </div>
        </div>
        <div className="exp-desk-analytics-chart exp-desk-analytics-chart--trend">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={trendData} margin={{ top: 10, right: 12, left: 0, bottom: 0 }}>
              <defs>
                <linearGradient id={`expTrendFill-${gradId}`} x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor={stroke} stopOpacity={isDark ? 0.45 : 0.28} />
                  <stop offset="70%" stopColor={stroke} stopOpacity={isDark ? 0.12 : 0.08} />
                  <stop offset="100%" stopColor={stroke} stopOpacity={0} />
                </linearGradient>
              </defs>
              <XAxis
                dataKey="label"
                tick={{ fill: tickFill, fontSize: 11 }}
                axisLine={false}
                tickLine={false}
                interval="preserveStartEnd"
                minTickGap={28}
                dy={4}
              />
              <YAxis
                tick={{ fill: tickFill, fontSize: 11 }}
                axisLine={false}
                tickLine={false}
                width={48}
                domain={hasTrendSpend ? [0, 'auto'] : [0, 1]}
                tickFormatter={(v) => (Number(v) >= 1000 ? `${Math.round(Number(v) / 1000)}k` : String(v))}
              />
              <Tooltip
                content={<TrendTooltip />}
                cursor={{ stroke: stroke, strokeWidth: 1, strokeDasharray: '4 4', opacity: 0.55 }}
              />
              <Area
                type="linear"
                dataKey="amount"
                stroke={stroke}
                strokeWidth={2.5}
                fill={`url(#expTrendFill-${gradId})`}
                activeDot={{ r: 5, strokeWidth: 0, fill: stroke }}
                dot={<LastPointDot dataLength={trendData.length} stroke={stroke} />}
                connectNulls
                isAnimationActive
                animationDuration={650}
              />
            </AreaChart>
          </ResponsiveContainer>
          {!hasTrendSpend ? (
            <div className="exp-desk-analytics-empty exp-desk-analytics-empty--overlay">
              <p>No posted or paid expenses in this date range. Adjust the dates or post expenses to see the trend.</p>
            </div>
          ) : null}
        </div>
        <div className="exp-desk-trend-foot" aria-label="Trend summary">
          <div className="exp-desk-trend-foot-main">
            <span className="exp-desk-trend-foot-label">
              Total posted ({formatCompactMoney(summary.total)})
            </span>
            <strong className="exp-desk-trend-foot-value">{formatMoney(summary.last)}</strong>
            <span className="exp-desk-trend-foot-hint">Latest period</span>
          </div>
          <div className={`exp-desk-trend-foot-change ${changeUp ? 'is-up' : 'is-down'}`}>
            <span className="exp-desk-trend-foot-pct">{changeLabel}</span>
            <div className="exp-desk-trend-help" ref={helpWrapRef}>
              <button
                type="button"
                className="exp-desk-trend-foot-help"
                aria-label="About this chart"
                aria-expanded={helpOpen}
                aria-controls={helpId}
                title="About this chart"
                onClick={() => setHelpOpen((open) => !open)}
              >
                ?
              </button>
              {helpOpen ? (
                <div id={helpId} className="exp-desk-trend-help-pop" role="dialog" aria-label="Expense trend help">
                  <strong className="exp-desk-trend-help-title">About Expense Trend</strong>
                  <p>{helpCopy.overall}</p>
                  {helpCopy.latestChange ? (
                    <p>
                      <strong>Latest change: {helpCopy.latestChange.label}</strong>{' '}
                      {helpCopy.latestChange.detail}
                    </p>
                  ) : null}
                  <p>{helpCopy.reasons}</p>
                </div>
              ) : null}
            </div>
          </div>
        </div>
      </div>

      <div className="exp-desk-analytics-card exp-desk-analytics-card--status">
        <div className="exp-desk-analytics-head">
          <div>
            <h3 className="exp-desk-analytics-title">Expense Status Breakdown</h3>
            <p className="exp-desk-analytics-sub">Status of the expenses listed below</p>
          </div>
        </div>
        <div className="exp-desk-analytics-status-body">
          <div className="exp-desk-analytics-chart exp-desk-analytics-chart--donut">
            <PieChart width={160} height={160}>
              <Pie
                data={pieData}
                dataKey="value"
                nameKey="label"
                cx={80}
                cy={80}
                innerRadius={46}
                outerRadius={68}
                paddingAngle={pieData.length > 1 ? 1.5 : 0}
                stroke="#fff"
                strokeWidth={1}
                isAnimationActive={false}
              >
                {pieData.map((entry) => (
                  <Cell key={entry.key} fill={entry.color || '#cbd5e1'} />
                ))}
              </Pie>
              <Tooltip
                formatter={(value, name) => [`${value}`, name]}
                contentStyle={{ borderRadius: 12, border: '1px solid #e2e8f0' }}
              />
            </PieChart>
            <div className="exp-desk-donut-center">
              <strong>{breakdownTotal}</strong>
              <span>Total records</span>
            </div>
          </div>
          <ul className="exp-desk-status-legend">
            {pieData.map((row) => {
              const pct = breakdownTotal > 0 ? Math.round((row.value / breakdownTotal) * 100) : 0;
              return (
                <li key={row.key}>
                  <span className="exp-desk-status-legend-dot" style={{ background: row.color }} />
                  <span className="exp-desk-status-legend-label">{row.label}</span>
                  <span className="exp-desk-status-legend-count">{row.value}</span>
                  <span className="exp-desk-status-legend-pct">{pct}%</span>
                </li>
              );
            })}
          </ul>
        </div>
      </div>
    </section>
  );
}
