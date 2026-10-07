import React, { useEffect, useMemo, useState } from 'react';
import {
  HiOutlineBanknotes,
  HiOutlineChatBubbleLeftRight,
  HiOutlineCursorArrowRays,
  HiOutlineDocumentText,
  HiOutlineExclamationTriangle,
  HiOutlineEye,
  HiOutlineHeart,
  HiOutlineShoppingBag,
  HiOutlineUsers,
  HiXMark,
} from 'react-icons/hi2';
import './website-dashboard.css';

const number = (v) => (Number(v) || 0).toLocaleString('en');

function money(v) {
  const n = Number(v) || 0;
  if (n >= 1e9) return `TZS ${(n / 1e9).toFixed(2)}B`;
  if (n >= 1e6) return `TZS ${(n / 1e6).toFixed(1)}M`;
  if (n >= 1e3) return `TZS ${(n / 1e3).toFixed(1)}K`;
  return `TZS ${n.toLocaleString('en', { maximumFractionDigits: 0 })}`;
}

const pct = (v) => (v === null || v === undefined ? '\u2013' : `${Number(v).toLocaleString('en', { maximumFractionDigits: 1 })}%`);

function dayLabel(date, withYear = false) {
  const d = new Date(`${date}T00:00:00`);
  if (Number.isNaN(d.getTime())) return date;
  return d.toLocaleDateString('en-GB', withYear ? { day: 'numeric', month: 'short', year: 'numeric' } : { day: 'numeric', month: 'short' });
}

function bucketDays(days, range) {
  if (range <= 31) return days.map((d) => ({ ...d, label: dayLabel(d.date) }));
  const size = range <= 120 ? 7 : null;
  const groups = [];
  days.forEach((d, i) => {
    const key = size ? Math.floor(i / size) : d.date.slice(0, 7);
    let g = groups.find((x) => x.key === key);
    if (!g) {
      g = { key, count: 0, date: d.date, visitors: 0, product_views: 0, enquiries: 0, quotes: 0, revenue: 0, likes: 0 };
      groups.push(g);
    }
    g.count += 1;
    ['visitors', 'product_views', 'enquiries', 'quotes', 'revenue', 'likes'].forEach((k) => { g[k] += Number(d[k]) || 0; });
  });
  return groups.map((g) => ({
    ...g,
    label: size
      ? dayLabel(g.date)
      : new Date(`${g.date}T00:00:00`).toLocaleDateString('en-GB', { month: 'short', year: '2-digit' }),
  }));
}

function Change({ value, points = false }) {
  if (value === null || value === undefined) return <span className="wdash-change is-flat">No earlier data</span>;
  const up = value > 0;
  const flat = value === 0;
  const text = points ? `${Math.abs(value)} pts` : `${Math.abs(value)}%`;
  return (
    <span className={`wdash-change ${flat ? 'is-flat' : up ? 'is-up' : 'is-down'}`}>
      {flat ? '' : up ? '\u25B2 ' : '\u25BC '}
      {text} vs previous period
    </span>
  );
}

function smoothPath(pts) {
  return pts.reduce((acc, [x, y], i) => {
    if (i === 0) return `M${x},${y}`;
    const [px, py] = pts[i - 1];
    const mx = (px + x) / 2;
    return `${acc} C${mx},${py} ${mx},${y} ${x},${y}`;
  }, '');
}

function Spark({ values, color }) {
  const max = Math.max(1, ...values);
  if (values.length < 2) return null;
  const d = smoothPath(values.map((v, i) => [(i / (values.length - 1)) * 100, 28 - (v / max) * 26]));
  return (
    <svg className="wdash-spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true">
      <path d={d} fill="none" stroke={color} strokeWidth="2" vectorEffect="non-scaling-stroke" strokeLinecap="round" />
    </svg>
  );
}

function Kpi({ icon: Icon, label, value, change, points, hint, spark, color }) {
  return (
    <div className="wdash-kpi" style={{ '--kpi': color }}>
      <div className="wdash-kpi-head">
        <span className="wdash-kpi-icon"><Icon aria-hidden="true" /></span>
        <span className="wdash-kpi-label">{label}</span>
      </div>
      <div className="wdash-kpi-value">{value}</div>
      {hint ? <div className="wdash-kpi-hint">{hint}</div> : <Change value={change} points={points} />}
      {spark ? <Spark values={spark} color={color} /> : null}
    </div>
  );
}

function TrafficChart({ rows }) {
  const [hover, setHover] = useState(null);
  const W = 640;
  const H = 220;
  const pad = { l: 34, r: 10, t: 12, b: 26 };
  const max = Math.max(4, ...rows.map((r) => Math.max(r.visitors, r.product_views)));
  const step = rows.length > 1 ? (W - pad.l - pad.r) / (rows.length - 1) : 0;
  const x = (i) => pad.l + i * step;
  const y = (v) => pad.t + (H - pad.t - pad.b) * (1 - v / max);
  const line = (key) => smoothPath(rows.map((r, i) => [x(i), y(r[key])]));
  const area = (key) => `${line(key)} L${x(rows.length - 1)},${y(0)} L${pad.l},${y(0)} Z`;
  const ticks = [0, Math.round(max / 2), max];
  const labelEvery = Math.max(1, Math.ceil(rows.length / 8));
  const active = hover !== null ? rows[hover] : null;

  return (
    <div className="wdash-chart" onMouseLeave={() => setHover(null)}>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label="Visitors and product views over time">
        {ticks.map((t) => (
          <g key={t}>
            <line x1={pad.l} x2={W - pad.r} y1={y(t)} y2={y(t)} className="wdash-grid" />
            <text x={pad.l - 6} y={y(t) + 4} textAnchor="end" className="wdash-axis">{number(t)}</text>
          </g>
        ))}
        <path d={area('product_views')} className="wdash-area-views" />
        <path d={area('visitors')} className="wdash-area-visitors" />
        <path d={line('product_views')} className="wdash-line-views" />
        <path d={line('visitors')} className="wdash-line-visitors" />
        {rows.map((r, i) => (
          <g key={r.date}>
            {i % labelEvery === 0 ? <text x={x(i)} y={H - 6} textAnchor="middle" className="wdash-axis">{r.label}</text> : null}
            <rect
              x={x(i) - Math.max(step, 8) / 2}
              y={pad.t}
              width={Math.max(step, 8)}
              height={H - pad.t - pad.b}
              fill="transparent"
              onMouseEnter={() => setHover(i)}
            />
          </g>
        ))}
        {active ? (
          <g>
            <line x1={x(hover)} x2={x(hover)} y1={pad.t} y2={y(0)} className="wdash-hover-line" />
            <circle cx={x(hover)} cy={y(active.visitors)} r="4" className="wdash-dot-visitors" />
            <circle cx={x(hover)} cy={y(active.product_views)} r="4" className="wdash-dot-views" />
          </g>
        ) : null}
      </svg>
      {active ? (
        <div className="wdash-tip" style={{ left: `${(x(hover) / W) * 100}%` }}>
          <strong>{active.label}</strong>
          <span><i className="is-visitors" />Visitors {number(active.visitors)}</span>
          <span><i className="is-views" />Product views {number(active.product_views)}</span>
        </div>
      ) : null}
    </div>
  );
}

function EnquiryBars({ rows }) {
  const max = Math.max(2, ...rows.map((r) => r.enquiries));
  const labelEvery = Math.max(1, Math.ceil(rows.length / 8));
  return (
    <div className="wdash-bars" role="img" aria-label="Enquiries and quotes over time">
      {rows.map((r, i) => (
        <div className="wdash-bar-col" key={r.date} title={`${r.label}: ${r.enquiries} enquiries, ${r.quotes} quoted`}>
          <div className="wdash-bar-track">
            <div className="wdash-bar is-enquiries" style={{ height: `${(r.enquiries / max) * 100}%` }}>
              <div className="wdash-bar is-quotes" style={{ height: r.enquiries ? `${(r.quotes / r.enquiries) * 100}%` : 0 }} />
            </div>
          </div>
          <span className="wdash-bar-label">{i % labelEvery === 0 ? r.label : ''}</span>
        </div>
      ))}
    </div>
  );
}

const FUNNEL_COLORS = ['#2563eb', '#0f766e', '#d97706', '#4f46e5'];

function Funnel({ steps }) {
  const top = Math.max(1, steps[0].value);
  return (
    <ol className="wdash-funnel">
      {steps.map((s, i) => (
        <li key={s.label}>
          <div className="wdash-funnel-row">
            <span>{s.label}</span>
            <strong>{number(s.value)}</strong>
          </div>
          <div className="wdash-funnel-track">
            <div className="wdash-funnel-fill" style={{ width: `${Math.max(s.value ? 2 : 0, (s.value / top) * 100)}%`, '--bar': FUNNEL_COLORS[i % FUNNEL_COLORS.length] }} />
          </div>
          {s.rate !== undefined ? <div className="wdash-funnel-rate">{pct(s.rate)} {s.rateLabel}</div> : null}
        </li>
      ))}
    </ol>
  );
}

function TopList({ title, icon: Icon, rows, unit, empty }) {
  return (
    <section className="wdash-card">
      <h3 className="wdash-card-title"><Icon aria-hidden="true" />{title}</h3>
      {rows.length === 0 ? (
        <p className="wdash-empty">{empty}</p>
      ) : (
        <ol className="wdash-top">
          {rows.map((r, i) => (
            <li key={`${r.url}-${i}`}>
              <span className="wdash-top-rank">{i + 1}</span>
              <a href={r.url} target="_blank" rel="noopener noreferrer" className="wdash-top-product">
                <span className="wdash-top-thumb">
                  {r.image ? (
                    <img src={r.image} alt="" loading="lazy" onError={(e) => { e.currentTarget.style.visibility = 'hidden'; }} />
                  ) : null}
                </span>
                <span className="wdash-top-name">{r.name}</span>
              </a>
              <span className="wdash-top-count">{number(r.count)} {Number(r.count) === 1 ? unit.replace(/s$/, '') : unit}</span>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}

export default function WebsiteDashboard({ dashboard, websiteUrl }) {
  const d = dashboard || {};
  const totals = d.totals || {};
  const changes = d.changes || {};
  const rates = d.rates || {};
  const range = String(d.range || 'week');
  const days = d.days || [];
  const rows = useMemo(() => bucketDays(days, days.length), [days]);
  const series = (key) => rows.map((r) => Number(r[key]) || 0);
  const rangeHref = (n) => `${websiteUrl}${websiteUrl.includes('?') ? '&' : '?'}range=${n}`;
  const trackingStartedLate = d.tracking_since && d.tracking_since > d.from;
  const [offlineOpen, setOfflineOpen] = useState(Boolean(dashboard) && !d.shop_connected);

  useEffect(() => {
    if (!offlineOpen) return undefined;
    const timer = window.setTimeout(() => setOfflineOpen(false), 10000);
    return () => window.clearTimeout(timer);
  }, [offlineOpen]);

  return (
    <section className="wdash" aria-label="Website dashboard">
      <header className="wdash-head">
        <nav className="wdash-ranges" aria-label="Date range">
          {(d.ranges || []).map((r) => (
            <a key={r.key} href={rangeHref(r.key)} className={r.key === range ? 'is-active' : ''}>
              {r.label}
            </a>
          ))}
        </nav>
        <form className={`wdash-custom${range === 'custom' ? ' is-active' : ''}`} method="get" action={websiteUrl}>
          <label>
            <span>From</span>
            <input type="date" name="from" defaultValue={d.from} max={d.today} required />
          </label>
          <label>
            <span>To</span>
            <input type="date" name="to" defaultValue={d.to} max={d.today} required />
          </label>
          <button type="submit">Apply</button>
        </form>
      </header>

      {offlineOpen && (
        <div className="wdash-notify" role="status">
          <span className="wdash-notify-icon"><HiOutlineExclamationTriangle aria-hidden="true" /></span>
          <div className="wdash-notify-body">
            <strong>Shop stats unavailable</strong>
            <p>
              Visitors, product views and likes are unavailable because ultimate.co.tz did not respond. Enquiries, quotes and revenue are up to date.
            </p>
          </div>
          <button type="button" className="wdash-notify-close" onClick={() => setOfflineOpen(false)} aria-label="Close notification">
            <HiXMark aria-hidden="true" />
          </button>
        </div>
      )}

      {d.shop_connected && trackingStartedLate ? (
        <div className="wdash-note">
          Visitor and product-view tracking started on {dayLabel(d.tracking_since, true)}, so earlier days show zero.
        </div>
      ) : null}

      <div className="wdash-kpis">
        <Kpi icon={HiOutlineUsers} label="Visitors" value={number(totals.visitors)} change={changes.visitors} spark={series('visitors')} color="#2563eb" />
        <Kpi icon={HiOutlineEye} label="Product views" value={number(totals.product_views)} change={changes.product_views} spark={series('product_views')} color="#7c3aed" />
        <Kpi icon={HiOutlineChatBubbleLeftRight} label="Enquiries" value={number(totals.enquiries)} change={changes.enquiries} spark={series('enquiries')} color="#0f766e" />
        <Kpi icon={HiOutlineDocumentText} label="Quotes" value={number(totals.quotes)} change={changes.quotes} spark={series('quotes')} color="#d97706" />
        <Kpi icon={HiOutlineBanknotes} label="Revenue" value={money(totals.revenue)} change={changes.revenue} spark={series('revenue')} color="#16a34a" />
        <Kpi icon={HiOutlineShoppingBag} label="Sales" value={number(totals.sales)} change={changes.sales} color="#4f46e5" />
        <Kpi icon={HiOutlineCursorArrowRays} label="Conversion rate" value={pct(rates.visitor_to_enquiry)} change={changes.conversion} points color="#0891b2" />
        <Kpi icon={HiOutlineHeart} label="Likes" value={number(totals.likes)} change={changes.likes} spark={series('likes')} color="#e11d48" />
      </div>

      <div className="wdash-grid-2">
        <section className="wdash-card">
          <h3 className="wdash-card-title">Traffic</h3>
          <div className="wdash-legend">
            <span><i className="is-visitors" />Visitors</span>
            <span><i className="is-views" />Product views</span>
          </div>
          <TrafficChart rows={rows} />
        </section>
        <section className="wdash-card">
          <h3 className="wdash-card-title">Conversion funnel</h3>
          <Funnel
            steps={[
              { label: 'Visitors', value: totals.visitors },
              { label: 'Enquiries', value: totals.enquiries, rate: rates.visitor_to_enquiry, rateLabel: 'of visitors sent an enquiry' },
              { label: 'Quotes', value: totals.quotes, rate: rates.enquiry_to_quote, rateLabel: 'of enquiries were quoted' },
              { label: 'Sales', value: totals.sales, rate: rates.enquiry_to_sale, rateLabel: 'of enquiries became an invoice' },
            ]}
          />
        </section>
      </div>

      <div className="wdash-grid-3">
        <section className="wdash-card">
          <h3 className="wdash-card-title">Enquiries and quotes</h3>
          <div className="wdash-legend">
            <span><i className="is-enquiries" />Enquiries</span>
            <span><i className="is-quotes" />Quoted</span>
          </div>
          <EnquiryBars rows={rows} />
        </section>
        <TopList title="Most viewed products" icon={HiOutlineEye} rows={d.top_viewed || []} unit="views" empty="No product views in this period yet." />
        <TopList title="Most liked products" icon={HiOutlineHeart} rows={d.top_liked || []} unit="likes" empty={`No likes in this period. ${number(d.likes_total)} wishlist saves in total.`} />
      </div>
    </section>
  );
}
