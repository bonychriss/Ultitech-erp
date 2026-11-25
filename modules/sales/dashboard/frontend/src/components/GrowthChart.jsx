import { useMemo, useState } from 'react';
import { formatCompactAxis, formatMoney } from '../utils/dashboardFormat.js';

const RANGES = [
  { key: 'day', label: 'Daily' },
  { key: 'weekly', label: 'Weekly' },
  { key: 'monthly', label: 'Monthly' },
];

const CHART = {
  width: 760,
  height: 248,
  padLeft: 58,
  padRight: 16,
  padTop: 12,
  padBottom: 28,
};

const EMPTY_SCALE_MAX = 1_000_000;

function niceMax(value) {
  if (value <= 0) return EMPTY_SCALE_MAX;
  const magnitude = 10 ** Math.floor(Math.log10(value));
  const normalized = value / magnitude;
  const step = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
  return step * magnitude;
}

function changeFromLast(points) {
  const values = points.map((point) => Number(point.value) || 0);
  let last = values.length - 1;
  while (last > 0 && values[last] === 0) last -= 1;
  const prev = last - 1;
  if (prev < 0) return 0;
  if (values[prev] === 0) return values[last] > 0 ? 100 : 0;
  return Number((((values[last] - values[prev]) / values[prev]) * 100).toFixed(1));
}

function countFromSeries(series, rangeKey) {
  return (series[rangeKey] || []).reduce((total, point) => total + (Number(point.value) || 0), 0);
}

export default function GrowthChart({
  title,
  series = {},
  comparisonSeries = {},
  orderSeries = {},
  quoteCountSeries = {},
  range,
  onRangeChange,
  theme = 'performance',
  ariaLabel = 'Sales performance chart',
}) {
  const [activeIndex, setActiveIndex] = useState(null);
  const [internalRange, setInternalRange] = useState('monthly');
  const [focus, setFocus] = useState('all');
  const [settingsOpen, setSettingsOpen] = useState(false);
  const activeRange = range || internalRange;
  const points = series[activeRange] || [];
  const comparisonPoints = comparisonSeries[activeRange] || [];
  const orderPoints = orderSeries[activeRange] || [];
  const quoteCountPoints = quoteCountSeries[activeRange] || [];

  const salesTotal = points.reduce((total, point) => total + (Number(point.value) || 0), 0);
  const quoteTotal = comparisonPoints.reduce((total, point) => total + (Number(point.value) || 0), 0);
  const orderCount = countFromSeries(orderSeries, activeRange);
  const quoteCount = countFromSeries(quoteCountSeries, activeRange) || comparisonPoints.filter((point) => Number(point.value) > 0).length;
  const avgOrder = orderCount > 0 ? salesTotal / orderCount : 0;
  const conversion = quoteCount > 0 ? (orderCount / quoteCount) * 100 : 0;

  const kpis = [
    { key: 'sales', label: 'Total Sales', value: formatMoney(salesTotal), delta: changeFromLast(points) },
    { key: 'quote', label: 'Total Quotation', value: formatMoney(quoteTotal), delta: changeFromLast(comparisonPoints) },
    { key: 'avg', label: 'Avg. Order Value', value: formatMoney(avgOrder), delta: changeFromLast(points) },
    { key: 'conv', label: 'Conversion Rate', value: `${conversion.toFixed(1)}%`, delta: changeFromLast(orderPoints) },
  ];

  const chart = useMemo(() => {
    const n = Math.max(points.length, comparisonPoints.length, orderPoints.length, 1);
    const salesValues = Array.from({ length: n }, (_, index) => Number(points[index]?.value) || 0);
    const quoteValues = Array.from({ length: n }, (_, index) => Number(comparisonPoints[index]?.value) || 0);
    const orderValues = Array.from({ length: n }, (_, index) => Number(orderPoints[index]?.value) || 0);
    const labels = Array.from({ length: n }, (_, index) => points[index]?.label || comparisonPoints[index]?.label || orderPoints[index]?.label || '');
    const moneyMax = niceMax(Math.max(...salesValues, ...quoteValues, 0));
    const orderMax = Math.max(1, Math.ceil(Math.max(...orderValues, 0) * 1.25) || 1);
    const plotW = CHART.width - CHART.padLeft - CHART.padRight;
    const plotH = CHART.height - CHART.padTop - CHART.padBottom;
    const step = plotW / n;
    const groupW = Math.min(52, Math.max(28, step * 0.42));
    const barW = Math.max(10, (groupW - 6) / 2);
    const coords = labels.map((label, index) => {
      const cx = CHART.padLeft + step * index + step / 2;
      const sales = salesValues[index];
      const quote = quoteValues[index];
      const orders = orderValues[index];
      const salesH = (sales / moneyMax) * plotH;
      const quoteH = (quote / moneyMax) * plotH;
      return {
        label,
        sales,
        quote,
        orders,
        x: cx,
        salesX: cx - barW / 2 - 2.5,
        quoteX: cx + barW / 2 + 2.5,
        barW,
        salesY: CHART.padTop + plotH - salesH,
        quoteY: CHART.padTop + plotH - quoteH,
        salesH,
        quoteH,
        orderY: CHART.padTop + plotH - (orders / orderMax) * plotH,
      };
    });
    const yTicks = [0, 0.25, 0.5, 0.75, 1].map((ratio) => ({
      value: moneyMax * ratio,
      y: CHART.padTop + plotH - ratio * plotH,
    }));
    const orderPath = coords.reduce((path, point, index) => (
      `${path}${index === 0 ? 'M' : 'L'} ${point.x} ${point.orderY} `
    ), '').trim();

    return { coords, yTicks, plotH, orderPath };
  }, [points, comparisonPoints, orderPoints]);

  const activePoint = activeIndex == null ? null : chart.coords[activeIndex];
  const showSales = focus === 'all' || focus === 'sales';
  const showQuotes = focus === 'all' || focus === 'quotations';
  const showOrders = focus === 'all' || focus === 'orders';

  return (
    <div className={`growth-chart growth-chart--${theme}`}>
      <div className="growth-chart__header">
        <div
          className="growth-chart__title-group"
          onBlur={(event) => {
            if (!event.currentTarget.contains(event.relatedTarget)) setSettingsOpen(false);
          }}
          onKeyDown={(event) => {
            if (event.key === 'Escape') setSettingsOpen(false);
          }}
        >
          <button
            type="button"
            className="growth-chart__title-icon"
            aria-label="Chart series"
            aria-expanded={settingsOpen}
            onClick={() => setSettingsOpen((open) => !open)}
          >
            <i className="fas fa-chart-bar" aria-hidden="true" />
          </button>
          {settingsOpen ? (
            <div className="growth-chart__type-menu" role="menu">
              <button type="button" role="menuitem" className={`growth-chart__type-option${focus === 'all' ? ' is-active' : ''}`} onClick={() => { setFocus('all'); setSettingsOpen(false); }}>Sales</button>
              <button type="button" role="menuitem" className={`growth-chart__type-option${focus === 'quotations' ? ' is-active' : ''}`} onClick={() => { setFocus('quotations'); setSettingsOpen(false); }}>Quotations</button>
              <button type="button" role="menuitem" className={`growth-chart__type-option${focus === 'orders' ? ' is-active' : ''}`} onClick={() => { setFocus('orders'); setSettingsOpen(false); }}>Orders</button>
            </div>
          ) : null}
          <h3 className="growth-chart__title">{title}</h3>
        </div>
        <div className="growth-chart__period" role="tablist" aria-label={`${title} period`}>
          {RANGES.map((item) => (
            <button
              key={item.key}
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
          ))}
        </div>
      </div>

      <div className="growth-chart__kpis">
        {kpis.map((kpi) => (
          <div key={kpi.key} className={`growth-chart__kpi growth-chart__kpi--${kpi.key}`}>
            <span className="growth-chart__kpi-label">
              <i className="growth-chart__kpi-dot" aria-hidden="true" />
              {kpi.label}
            </span>
            <strong>{kpi.value}</strong>
            <span className={`growth-chart__kpi-delta${kpi.delta >= 0 ? ' is-up' : ' is-down'}`}>
              {kpi.delta >= 0 ? '↑' : '↓'} {Math.abs(kpi.delta).toFixed(1)}%
            </span>
          </div>
        ))}
      </div>

      <div className="growth-chart__canvas-wrap">
        <svg
          className="growth-chart__svg"
          viewBox={`0 0 ${CHART.width} ${CHART.height}`}
          role="img"
          aria-label={ariaLabel}
          onPointerLeave={() => setActiveIndex(null)}
        >
          <defs>
            <linearGradient id="salesBarFill" x1="0" y1="1" x2="0" y2="0">
              <stop offset="0%" stopColor="#60a5fa" />
              <stop offset="100%" stopColor="#2563eb" />
            </linearGradient>
            <linearGradient id="quoteBarFill" x1="0" y1="1" x2="0" y2="0">
              <stop offset="0%" stopColor="#86efac" />
              <stop offset="100%" stopColor="#22c55e" />
            </linearGradient>
          </defs>
          {chart.yTicks.map((tick) => (
            <g key={tick.value}>
              <line x1={CHART.padLeft} y1={tick.y} x2={CHART.width - CHART.padRight} y2={tick.y} className="growth-chart__grid" />
              <text x={CHART.padLeft - 8} y={tick.y + 3} className="growth-chart__y-label">TZS {formatCompactAxis(tick.value)}</text>
            </g>
          ))}
          {chart.coords.map((point, index) => (
            <g key={`${point.label}-${index}`}>
              {showSales ? (
                <rect
                  className="growth-chart__bar growth-chart__bar--sales"
                  x={point.salesX - point.barW / 2}
                  y={point.salesY}
                  width={point.barW}
                  height={Math.max(0, point.salesH)}
                  rx="7"
                  fill="url(#salesBarFill)"
                />
              ) : null}
              {showQuotes ? (
                <rect
                  className="growth-chart__bar growth-chart__bar--quote"
                  x={point.quoteX - point.barW / 2}
                  y={point.quoteY}
                  width={point.barW}
                  height={Math.max(0, point.quoteH)}
                  rx="7"
                  fill="url(#quoteBarFill)"
                />
              ) : null}
              <rect
                x={point.x - (point.barW + 4)}
                y={CHART.padTop}
                width={(point.barW + 4) * 2}
                height={chart.plotH}
                fill="transparent"
                onPointerEnter={() => setActiveIndex(index)}
              />
              <text x={point.x} y={CHART.height - 8} className="growth-chart__x-label">{point.label}</text>
            </g>
          ))}
          {showOrders && chart.orderPath ? (
            <path d={chart.orderPath} className="growth-chart__order-line" fill="none" />
          ) : null}
          {showOrders ? chart.coords.map((point, index) => (
            <circle key={`order-${point.label}-${index}`} cx={point.x} cy={point.orderY} r={activeIndex === index ? 4.5 : 3.2} className="growth-chart__order-dot" />
          )) : null}
        </svg>
        {activePoint ? (
          <div
            className="growth-chart__tooltip"
            style={{ left: `${(activePoint.x / CHART.width) * 100}%`, top: `${(Math.min(activePoint.salesY, activePoint.quoteY) / CHART.height) * 100}%` }}
          >
            <div className="growth-chart__tooltip-date">{activePoint.label}</div>
            <div className="growth-chart__tooltip-row is-sales"><span>Sales</span><strong>{formatMoney(activePoint.sales)}</strong></div>
            <div className="growth-chart__tooltip-row is-quote"><span>Quotation</span><strong>{formatMoney(activePoint.quote)}</strong></div>
            <div className="growth-chart__tooltip-row is-orders"><span>Orders</span><strong>{activePoint.orders}</strong></div>
          </div>
        ) : null}
      </div>
      <div className="growth-chart__legend" aria-label="Chart legend">
        <span><i className="growth-chart__legend-dot is-sales" />Sales</span>
        <span><i className="growth-chart__legend-dot is-quote" />Quotation</span>
        <span><i className="growth-chart__legend-dot is-orders" />Orders</span>
      </div>
    </div>
  );
}
