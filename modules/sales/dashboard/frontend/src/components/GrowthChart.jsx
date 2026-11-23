import { useMemo, useState } from 'react';
import { formatCompactAxis, formatMoney } from '../utils/dashboardFormat.js';

const RANGES = [
  { key: 'day', label: 'Day' },
  { key: 'weekly', label: 'Weekly' },
  { key: 'monthly', label: 'Monthly' },
];

const CHART = {
  width: 640,
  height: 250,
  padLeft: 52,
  padRight: 16,
  padTop: 16,
  padBottom: 34,
};

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

export default function GrowthChart({
  title,
  series = {},
  comparisonSeries = {},
  seriesLabel = 'Sales Revenue',
  comparisonLabel = 'Quotation Value',
  range,
  onRangeChange,
  theme = 'quote',
  ariaLabel = 'Growth chart',
}) {
  const [activeIndex, setActiveIndex] = useState(null);
  const [internalRange, setInternalRange] = useState('monthly');
  const activeRange = range || internalRange;
  const points = series[activeRange] || [];
  const comparisonPoints = comparisonSeries[activeRange] || [];
  const seriesTotal = points.reduce((total, point) => total + (Number(point.value) || 0), 0);
  const comparisonTotal = comparisonPoints.reduce((total, point) => total + (Number(point.value) || 0), 0);

  const chart = useMemo(() => {
    const values = points.map((point) => Number(point.value) || 0);
    const comparisonValues = comparisonPoints.map((point) => Number(point.value) || 0);
    const maxVal = niceMax(Math.max(...values, ...comparisonValues, 0));
    const plotW = CHART.width - CHART.padLeft - CHART.padRight;
    const plotH = CHART.height - CHART.padTop - CHART.padBottom;
    const step = points.length > 1 ? plotW / (points.length - 1) : plotW;
    const coords = points.map((point, index) => {
      const value = Number(point.value) || 0;
      return {
        label: point.label,
        value,
        x: CHART.padLeft + (points.length > 1 ? index * step : plotW / 2),
        y: CHART.padTop + plotH - (value / maxVal) * plotH,
      };
    });
    const comparisonCoords = comparisonPoints.map((point, index) => {
      const value = Number(point.value) || 0;
      return {
        label: point.label,
        value,
        x: CHART.padLeft + (comparisonPoints.length > 1 ? index * plotW / (comparisonPoints.length - 1) : plotW / 2),
        y: CHART.padTop + plotH - (value / maxVal) * plotH,
      };
    });
    const yTicks = [0, 0.2, 0.4, 0.6, 0.8, 1].map((ratio) => ({
      value: maxVal * ratio,
      y: CHART.padTop + plotH - ratio * plotH,
    }));

    return {
      coords,
      comparisonCoords,
      yTicks,
      plotW,
      plotH,
      linePath: buildSmoothPath(coords),
      comparisonLinePath: buildSmoothPath(comparisonCoords),
    };
  }, [points, comparisonPoints]);

  const activePoint = activeIndex == null ? null : chart.coords[activeIndex];
  const activeComparisonPoint = activeIndex == null ? null : chart.comparisonCoords[activeIndex];

  return (
    <div className={`growth-chart growth-chart--${theme}`}>
      <div className="growth-chart__header">
        <h3 className="growth-chart__title">
          <i className="fas fa-chart-line" aria-hidden="true" />
          {title}
        </h3>
        <div className="growth-chart__period" role="tablist" aria-label={`${title} period`}>
          {RANGES.map((item) => (
            <span key={item.key} className="growth-chart__period-item-wrap">
              <button
                type="button"
                role="tab"
                aria-selected={activeRange === item.key}
                className={`growth-chart__period-item${activeRange === item.key ? ' is-active' : ''}`}
                onClick={() => {
                  setInternalRange(item.key);
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

      <div className="growth-chart__summary" aria-live="polite">
        <div className="growth-chart__summary-total">
          <div className="growth-chart__metric">
            <span className="growth-chart__metric-label">{seriesLabel}</span>
            <strong>{formatMoney(seriesTotal)}</strong>
          </div>
          <div className="growth-chart__metric is-comparison">
            <span className="growth-chart__metric-label">{comparisonLabel}</span>
            <strong>{formatMoney(comparisonTotal)}</strong>
          </div>
        </div>
        <div className="growth-chart__legend" aria-label="Chart legend">
          <span><i className="growth-chart__legend-dot is-current" />{seriesLabel}</span>
          <span><i className="growth-chart__legend-dot is-previous" />{comparisonLabel}</span>
        </div>
      </div>

      <div className="growth-chart__canvas-wrap">
        <svg
          className="growth-chart__svg"
          viewBox={`0 0 ${CHART.width} ${CHART.height}`}
          role="img"
          aria-label={ariaLabel}
          onPointerLeave={() => setActiveIndex(null)}
        >
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
            <line
              key={`v-grid-${point.label}-${index}`}
              x1={point.x}
              y1={CHART.padTop}
              x2={point.x}
              y2={CHART.height - CHART.padBottom}
              className="growth-chart__vertical-grid"
            />
          ))}

          {activePoint ? (
            <line
              x1={activePoint.x}
              y1={CHART.padTop}
              x2={activePoint.x}
              y2={CHART.height - CHART.padBottom}
              className="growth-chart__active-guide"
            />
          ) : null}

          <path d={chart.comparisonLinePath} className="growth-chart__comparison-line" fill="none" />
          <path d={chart.linePath} className="growth-chart__line" fill="none" />

          {activePoint && activeComparisonPoint ? (
            <>
              <circle cx={activeComparisonPoint.x} cy={activeComparisonPoint.y} r="4" className="growth-chart__comparison-dot is-active" />
              <circle cx={activePoint.x} cy={activePoint.y} r="4.5" className="growth-chart__dot is-active" />
            </>
          ) : null}

          {chart.coords.map((point, index) => {
            const left = index === 0 ? CHART.padLeft : (chart.coords[index - 1].x + point.x) / 2;
            const right = index === chart.coords.length - 1
              ? CHART.width - CHART.padRight
              : (point.x + chart.coords[index + 1].x) / 2;
            return (
              <rect
                key={`hit-${point.label}-${index}`}
                x={left}
                y={CHART.padTop}
                width={Math.max(1, right - left)}
                height={chart.plotH}
                fill="transparent"
                onPointerEnter={() => setActiveIndex(index)}
              />
            );
          })}

          {chart.coords.map((point, index) => (
            <text
              key={`x-${point.label}-${index}`}
              x={point.x}
              y={CHART.height - 8}
              className="growth-chart__x-label"
            >
              {point.label}
            </text>
          ))}
        </svg>

        {activePoint && activeComparisonPoint ? (
          <div
            className="growth-chart__tooltip"
            style={{
              left: `${(activePoint.x / CHART.width) * 100}%`,
              top: `${(Math.min(activePoint.y, activeComparisonPoint.y) / CHART.height) * 100}%`,
            }}
          >
            <div className="growth-chart__tooltip-date">{activePoint.label}</div>
            <div className="growth-chart__tooltip-row">
              <span>{seriesLabel}</span>
              <strong>{formatMoney(activePoint.value)}</strong>
            </div>
            <div className="growth-chart__tooltip-row is-previous">
              <span>{comparisonLabel}</span>
              <strong>{formatMoney(activeComparisonPoint.value)}</strong>
            </div>
          </div>
        ) : null}
      </div>
    </div>
  );
}
