import { useMemo, useState } from 'react';
import { formatCompactAxis, formatMoney } from '../utils/dashboardFormat.js';

const RANGES = [
  { key: 'day', label: 'Day' },
  { key: 'weekly', label: 'Weekly' },
  { key: 'monthly', label: 'Monthly' },
];

const CHART = {
  width: 640,
  height: 260,
  padLeft: 52,
  padRight: 16,
  padTop: 24,
  padBottom: 36,
};

/** Sensible TZS chart ceiling when data is flat or empty. */
const EMPTY_SCALE_MAX = 1_000_000;

function niceMax(value) {
  if (value <= 0) return EMPTY_SCALE_MAX;
  const magnitude = 10 ** Math.floor(Math.log10(value));
  const normalized = value / magnitude;
  const step = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
  return step * magnitude;
}

function buildSmoothPath(points) {
  if (points.length < 2) return '';
  let d = `M ${points[0].x} ${points[0].y}`;
  for (let i = 0; i < points.length - 1; i += 1) {
    const p0 = points[i];
    const p1 = points[i + 1];
    const cx = (p0.x + p1.x) / 2;
    d += ` C ${cx} ${p0.y}, ${cx} ${p1.y}, ${p1.x} ${p1.y}`;
  }
  return d;
}

function buildAreaPath(points, baselineY) {
  if (!points.length) return '';
  const line = buildSmoothPath(points);
  const last = points[points.length - 1];
  const first = points[0];
  return `${line} L ${last.x} ${baselineY} L ${first.x} ${baselineY} Z`;
}

export default function GrowthChart({
  title,
  series = {},
  tooltipLabel = 'Total :',
  range,
  onRangeChange,
  theme = 'revenue',
  gradientId = 'growth-area-gradient',
  ariaLabel = 'Growth chart',
  autoCycle = false,
}) {
  const [activeIndex, setActiveIndex] = useState(null);
  const points = series[range] || [];

  const chart = useMemo(() => {
    const values = points.map((p) => Number(p.value) || 0);
    const dataMax = Math.max(...values, 0);
    const maxVal = dataMax > 0 ? niceMax(dataMax) : EMPTY_SCALE_MAX;
    const plotW = CHART.width - CHART.padLeft - CHART.padRight;
    const plotH = CHART.height - CHART.padTop - CHART.padBottom;
    const step = points.length > 1 ? plotW / (points.length - 1) : plotW;
    const barW = Math.min(14, step * 0.24);

    const coords = points.map((point, index) => {
      const value = Number(point.value) || 0;
      const x = CHART.padLeft + (points.length > 1 ? index * step : plotW / 2);
      const y = CHART.padTop + plotH - (value / maxVal) * plotH;
      const barH = (value / maxVal) * plotH;
      return {
        label: point.label,
        value,
        x,
        y,
        barX: x - barW / 2,
        barY: CHART.padTop + plotH - barH,
        barW,
        barH,
      };
    });

    const yTicks = [0, 0.2, 0.4, 0.6, 0.8, 1].map((ratio) => ({
      value: maxVal * ratio,
      y: CHART.padTop + plotH - ratio * plotH,
    }));

    const baselineY = CHART.padTop + plotH;

    return {
      coords,
      yTicks,
      maxVal,
      baselineY,
      linePath: buildSmoothPath(coords),
      areaPath: buildAreaPath(coords, baselineY),
    };
  }, [points]);

  const activePoint = activeIndex != null ? chart.coords[activeIndex] : null;

  return (
    <div className={`growth-chart growth-chart--${theme}`}>
      <div className="growth-chart__header">
        <h3 className="growth-chart__title">{title}</h3>
        <div
          className={`growth-chart__period${autoCycle ? ' growth-chart__period--autocycle' : ''}`}
          role="tablist"
          aria-label={`${title} period`}
        >
          {RANGES.map((item, index) => (
            <span key={item.key} className="growth-chart__period-item-wrap">
              {index > 0 ? <span className="growth-chart__period-sep" aria-hidden="true" /> : null}
              <button
                type="button"
                role="tab"
                aria-selected={range === item.key}
                className={`growth-chart__period-item${range === item.key ? ' is-active' : ''}`}
                onClick={() => {
                  onRangeChange?.(item.key);
                  setActiveIndex(null);
                }}
              >
                {item.label}
              </button>
            </span>
          ))}
        </div>
      </div>

      <div className="growth-chart__canvas-wrap">
        <svg
          className="growth-chart__svg"
          viewBox={`0 0 ${CHART.width} ${CHART.height}`}
          role="img"
          aria-label={ariaLabel}
        >
          <defs>
            <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="var(--growth-line)" stopOpacity="0.28" />
              <stop offset="100%" stopColor="var(--growth-line)" stopOpacity="0.02" />
            </linearGradient>
          </defs>

          {chart.yTicks.map((tick) => (
            <g key={tick.value}>
              <line
                x1={CHART.padLeft}
                y1={tick.y}
                x2={CHART.width - CHART.padRight}
                y2={tick.y}
                className="growth-chart__grid"
              />
              <text x={CHART.padLeft - 8} y={tick.y + 4} className="growth-chart__y-label">
                {formatCompactAxis(tick.value)}
              </text>
            </g>
          ))}

          {chart.coords.map((point, index) => (
            <rect
              key={`bar-${point.label}-${index}`}
              x={point.barX}
              y={point.barY}
              width={point.barW}
              height={point.barH}
              rx={point.barW / 2}
              className="growth-chart__bar"
              onMouseEnter={() => setActiveIndex(index)}
              onMouseLeave={() => setActiveIndex(null)}
            />
          ))}

          <path d={chart.areaPath} fill={`url(#${gradientId})`} />
          <path d={chart.linePath} className="growth-chart__line" fill="none" />

          {chart.coords.map((point, index) => (
            <circle
              key={`dot-${point.label}-${index}`}
              cx={point.x}
              cy={point.y}
              r={activeIndex === index ? 6 : 4}
              className={`growth-chart__dot${activeIndex === index ? ' is-active' : ''}`}
              onMouseEnter={() => setActiveIndex(index)}
              onMouseLeave={() => setActiveIndex(null)}
            />
          ))}

          {chart.coords.map((point, index) => (
            <text
              key={`x-${point.label}-${index}`}
              x={point.x}
              y={CHART.height - 10}
              className="growth-chart__x-label"
            >
              {point.label}
            </text>
          ))}
        </svg>

        {activePoint ? (
          <div
            className="growth-chart__tooltip"
            style={{
              left: `${(activePoint.x / CHART.width) * 100}%`,
              top: `${(activePoint.y / CHART.height) * 100}%`,
            }}
          >
            <div className="growth-chart__tooltip-label">{tooltipLabel}</div>
            <div className="growth-chart__tooltip-value">{formatMoney(activePoint.value)}</div>
          </div>
        ) : null}
      </div>
    </div>
  );
}
