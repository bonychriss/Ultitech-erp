import {
  AlertTriangle,
  ArrowDown,
  ArrowRight,
  ArrowUp,
  Clock,
  DollarSign,
  Handshake,
  Loader2,
  Trophy,
  Truck,
  Cog,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { fetchDashboardInit } from '../api/dashboardDesk.js';
import {
  formatMoney,
  formatNumber,
  formatPercent,
  timeAgo,
} from '../utils/dashboardFormat.js';
import MostSoldProductsList from '../components/MostSoldProductsList.jsx';
import KpiSummaryModal from '../components/KpiSummaryModal.jsx';
import AlternatingGrowthCharts from '../components/AlternatingGrowthCharts.jsx';

function FlashAlerts({ flash }) {
  if (!flash?.success && !flash?.error) return null;
  return (
    <>
      {flash.success ? (
        <div className="alert alert-success alert-dismissible fade show sd-flash" role="alert">
          <i className="fas fa-check-circle me-2" />
          {flash.success}
          <button type="button" className="btn-close" data-bs-dismiss="alert" aria-label="Close" />
        </div>
      ) : null}
      {flash.error ? (
        <div className="alert alert-danger alert-dismissible fade show sd-flash" role="alert">
          <i className="fas fa-exclamation-circle me-2" />
          {flash.error}
          <button type="button" className="btn-close" data-bs-dismiss="alert" aria-label="Close" />
        </div>
      ) : null}
    </>
  );
}

function KpiCard({
  icon,
  iconClass,
  title,
  value,
  subtext,
  trend,
  trendClass,
  onClick,
}) {
  return (
    <div
      className="kpi-card kpi-card--clickable"
      role="button"
      tabIndex={0}
      onClick={onClick}
      onKeyDown={(event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          onClick?.();
        }
      }}
      aria-label={`View ${title} summary`}
    >
      <div className="kpi-card-header">
        <div className={`kpi-card-icon ${iconClass}`}>{icon}</div>
        <div className="kpi-card-title">{title}</div>
      </div>
      <div className="kpi-card-value">
        {value}
        {trend != null ? (
          <span className={`kpi-trend-indicator ${trendClass || ''}`}>{trend}</span>
        ) : null}
      </div>
      {subtext ? <div className="kpi-card-subtext">{subtext}</div> : null}
    </div>
  );
}

function RecentActivity({ activities }) {
  if (!activities?.length) {
    return <div className="text-center text-muted small py-3">No recent updates</div>;
  }

  return (
    <div className="activity-list">
      {activities.map((act) => {
        const isOrder = act.type === 'order';
        const iconClass = isOrder ? 'blue' : 'green';
        return (
          <div className="activity-item" key={`${act.type}-${act.ref_number}-${act.created_at}`}>
            <div className={`activity-icon ${iconClass}`}>
              <i className={`fas ${isOrder ? 'fa-file-invoice' : 'fa-file-invoice-dollar'}`} />
            </div>
            <div className="activity-content">
              <div className="activity-text">
                <a href={act.url}>{act.ref_number}</a>
                {' - '}
                {act.customer_name}
                {' - '}
                {formatMoney(act.total_amount)}
              </div>
              <div className="activity-time">{timeAgo(act.created_at)}</div>
            </div>
          </div>
        );
      })}
    </div>
  );
}

function Leaderboard({ rows, target }) {
  if (!rows?.length) {
    return <div className="text-center text-muted small py-3">No data yet</div>;
  }

  const targetLabel = target >= 1_000_000 ? `${Math.round(target / 1_000_000)}M` : formatNumber(target);

  return (
    <>
      {rows.map((rep, index) => {
        const hitTarget = target > 0 && rep.total_sold >= target;
        return (
          <div
            className={`leaderboard-item${hitTarget ? ' leaderboard-item--target-hit' : ''}`}
            key={`${rep.username}-${index}`}
          >
            {hitTarget ? (
              <>
                <span className="leaderboard-congrats-spark leaderboard-congrats-spark--1" aria-hidden="true" />
                <span className="leaderboard-congrats-spark leaderboard-congrats-spark--2" aria-hidden="true" />
                <span className="leaderboard-congrats-spark leaderboard-congrats-spark--3" aria-hidden="true" />
              </>
            ) : null}
            <span className="leaderboard-rank">{index + 1}</span>
            <LeaderboardAvatar rep={rep} hitTarget={hitTarget} />
            <div className="leaderboard-info">
              <div className="leaderboard-name-row">
                <div className="leaderboard-name">{rep.username}</div>
                {hitTarget ? (
                  <span className="leaderboard-congrats-badge">
                    <Trophy size={12} strokeWidth={2.5} aria-hidden="true" />
                    Target reached!
                  </span>
                ) : null}
              </div>
              <div className="leaderboard-progress-wrapper">
                <div className="leaderboard-progress-bar">
                  <div
                    className={`leaderboard-progress-fill${hitTarget ? ' leaderboard-progress-fill--complete' : ''}`}
                    style={{ width: `${rep.progress_percent}%` }}
                  />
                </div>
                <div className="leaderboard-progress-text">
                  <span className="text-muted small">{formatPercent(rep.progress_percent)}%</span>
                  <span className="text-muted small ms-2">
                    of
                    {' '}
                    {targetLabel}
                  </span>
                </div>
              </div>
            </div>
            <div className="leaderboard-sales">{formatMoney(rep.total_sold)}</div>
          </div>
        );
      })}
    </>
  );
}

function LeaderboardAvatar({ rep, hitTarget = false }) {
  const [failed, setFailed] = useState(false);
  if (rep.avatar_url && !failed) {
    return (
      <div className={`leaderboard-avatar${hitTarget ? ' leaderboard-avatar--target-hit' : ''}`}>
        <img
          src={rep.avatar_url}
          alt=""
          onError={() => setFailed(true)}
        />
      </div>
    );
  }
  return (
    <div className={`leaderboard-avatar${hitTarget ? ' leaderboard-avatar--target-hit' : ''}`}>
      {rep.initial}
    </div>
  );
}

function YearlyTarget({ yearly }) {
  const target = Number(yearly?.target || 0);
  const sales = Number(yearly?.sales || 0);
  const percent = Number(yearly?.percent || 0);
  const hitTarget = target > 0 && sales >= target;

  return (
    <div className={`dash-card flex-shrink-0 sd-yearly-card${hitTarget ? ' sd-yearly-card--target-hit' : ''}`}>
      {hitTarget ? (
        <>
          <span className="yearly-congrats-spark yearly-congrats-spark--1" aria-hidden="true" />
          <span className="yearly-congrats-spark yearly-congrats-spark--2" aria-hidden="true" />
          <span className="yearly-congrats-spark yearly-congrats-spark--3" aria-hidden="true" />
        </>
      ) : null}
      <div className="sd-yearly-card-head">
        <h3 className="dash-card-title mb-2">Yearly Target</h3>
        {hitTarget ? (
          <span className="yearly-congrats-badge">
            <Trophy size={13} strokeWidth={2.5} aria-hidden="true" />
            Target reached!
          </span>
        ) : null}
      </div>
      <div className="d-flex justify-content-between align-items-end mb-1">
        <div>
          <div className="text-muted small">Achieved</div>
          <div className={`fw-bold${hitTarget ? ' yearly-achieved-value' : ' text-primary'}`}>
            {formatMoney(sales)}
          </div>
        </div>
        <div className="text-end">
          <div className="text-muted small">Target</div>
          <div className="fw-bold">{formatMoney(target)}</div>
        </div>
      </div>
      <div className={`progress yearly-progress${hitTarget ? ' yearly-progress--complete' : ''}`} style={{ height: '8px' }}>
        <div
          className={`progress-bar${hitTarget ? ' yearly-progress-bar--complete' : ' bg-primary'}`}
          role="progressbar"
          style={{ width: `${percent}%` }}
        />
      </div>
      <div className={`text-center mt-2 small${hitTarget ? ' yearly-complete-text' : ' text-muted'}`}>
        {hitTarget ? (
          <>
            <Trophy size={14} strokeWidth={2.5} className="me-1" aria-hidden="true" />
            Congratulations! Annual target achieved.
          </>
        ) : (
          <>
            {formatPercent(percent)}
            % Completed
          </>
        )}
      </div>
    </div>
  );
}

export default function DashboardPage() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [activeKpiKey, setActiveKpiKey] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams(window.location.search);
      const payload = await fetchDashboardInit(params);
      setData(payload);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load dashboard.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  if (loading) {
    return (
      <div className="sd-loading">
        <Loader2 size={20} className="spin" style={{ animation: 'spin 1s linear infinite' }} />
        <span>Loading dashboard...</span>
      </div>
    );
  }

  if (error) {
    return (
      <div className="alert alert-danger" role="alert">
        {error}
      </div>
    );
  }

  if (!data) return null;

  const metrics = data.metrics || {};
  const salesTrend = Number(metrics.sales_trend) || 0;
  const trendUp = salesTrend >= 0;
  const summaries = data.kpi_summaries || {};
  const activeSummary = activeKpiKey ? summaries[activeKpiKey] : null;

  return (
    <>
      <FlashAlerts flash={data.flash} />

      <div className="kpi-overview">
        <KpiCard
          icon={<DollarSign size={14} />}
          iconClass="blue"
          title="Monthly Sales"
          value={formatMoney(metrics.sales_total)}
          trend={trendUp ? <ArrowUp size={12} /> : <ArrowDown size={12} />}
          trendClass={trendUp ? 'text-success' : 'text-danger'}
          onClick={() => setActiveKpiKey('monthly_sales')}
          subtext={(
            <>
              <span className={trendUp ? 'text-success' : 'text-danger'} style={{ fontWeight: 700 }}>
                {Math.abs(salesTrend)}
                %
              </span>
              {' '}
              from last month
            </>
          )}
        />
        <KpiCard
          icon={<Clock size={14} />}
          iconClass="orange"
          title="Pending Orders"
          value={formatNumber(metrics.pending_orders)}
          trend={<ArrowRight size={12} />}
          trendClass="text-warning"
          onClick={() => setActiveKpiKey('pending_orders')}
          subtext={(
            <>
              <span className="text-warning fw-bold">
                +
                {metrics.pending_new_today}
              </span>
              {' '}
              new today
            </>
          )}
        />
        <KpiCard
          icon={<AlertTriangle size={14} />}
          iconClass="red"
          title="Overdue Invoices"
          value={formatNumber(metrics.overdue_invoices)}
          trend={<ArrowUp size={12} />}
          trendClass="text-danger"
          onClick={() => setActiveKpiKey('overdue_invoices')}
          subtext={<span className="text-danger">Action required</span>}
        />
        <KpiCard
          icon={<Handshake size={14} />}
          iconClass="green"
          title={data.user?.is_admin ? 'Total Monthly Sales' : 'Monthly Sales'}
          value={formatMoney(metrics.monthly_sales_display)}
          trend={<ArrowUp size={12} />}
          trendClass="text-success"
          onClick={() => setActiveKpiKey('monthly_sales_scope')}
          subtext={data.user?.is_admin ? 'All sales this month' : 'Your sales this month'}
        />
      </div>

      <KpiSummaryModal
        summary={activeSummary}
        onClose={() => setActiveKpiKey(null)}
      />

      <div className="row g-3 mb-3">
        <div className="col-xl-8 col-lg-7">
          <div className="dash-card sd-revenue-growth-card">
            <AlternatingGrowthCharts
              revenueSeries={data.revenue_growth || {}}
              quoteSeries={data.quote_growth || {}}
            />
          </div>
        </div>
        <div className="col-xl-4 col-lg-5">
          <div className="dash-card sd-card-scroll sd-recent-activity-card">
            <h3 className="dash-card-title">Recent Activity</h3>
            <RecentActivity activities={data.recent_activities} />
          </div>
        </div>
      </div>

      <div className="row g-3 sd-dashboard-bottom-row align-items-start">
        <div className="col-xl-6 col-lg-6">
          <div className="dash-card sd-outgoing-card">
            {data.is_roadmaster ? (
              <>
                <h3 className="dash-card-title">Most Outgoing Trucks &amp; Spares</h3>
                <p className="most-sold-subtitle">
                  Most frequently outgoing in the last
                  {' '}
                  {data.most_sold_lookback_days}
                  {' '}
                  days
                </p>
                <div className="row g-3 most-sold-split">
                  <div className="col-md-6">
                    <div className="most-sold-panel most-sold-panel--truck">
                      <div className="most-sold-panel-heading">
                        <span className="most-sold-panel-icon"><Truck size={14} /></span>
                        <span>Most Outgoing Trucks</span>
                      </div>
                      <MostSoldProductsList
                        products={data.most_sold_trucks}
                        emptyMessage="No truck sales yet"
                        placeholderIcon="fa-truck"
                      />
                    </div>
                  </div>
                  <div className="col-md-6">
                    <div className="most-sold-panel most-sold-panel--spare">
                      <div className="most-sold-panel-heading">
                        <span className="most-sold-panel-icon"><Cog size={14} /></span>
                        <span>Most Outgoing Spares</span>
                      </div>
                      <MostSoldProductsList
                        products={data.most_sold_spares}
                        emptyMessage="No spare part sales yet"
                        placeholderIcon="fa-cog"
                      />
                    </div>
                  </div>
                </div>
              </>
            ) : (
              <>
                <h3 className="dash-card-title">Most Outgoing Products</h3>
                <p className="most-sold-subtitle">
                  Most frequently outgoing in the last
                  {' '}
                  {data.most_sold_lookback_days}
                  {' '}
                  days
                </p>
                <MostSoldProductsList
                  products={data.most_outgoing_products}
                  emptyMessage="No product data yet"
                  placeholderIcon="fa-box"
                />
              </>
            )}
          </div>
        </div>
        <div className="col-xl-6 col-lg-6">
          <div className="sd-sidebar-cards d-flex flex-column gap-3">
            <div className="dash-card sd-leaderboard-card sd-card-scroll">
              <div className="leaderboard-header">
                <h3 className="dash-card-title">Leaderboard</h3>
                <span className="leaderboard-subtitle">Total Sales</span>
              </div>
              <Leaderboard rows={data.leaderboard} target={data.leaderboard_target} />
            </div>
            <YearlyTarget yearly={data.yearly || {}} />
          </div>
        </div>
      </div>
    </>
  );
}
