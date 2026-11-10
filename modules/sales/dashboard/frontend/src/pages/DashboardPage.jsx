import {
  AlertTriangle,
  ArrowRight,
  ArrowUp,
  Clock,
  DollarSign,
  Handshake,
  Loader2,
  Trophy,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { fetchDashboardInit } from '../api/dashboardDesk.js';
import {
  formatMoney,
  formatNumber,
  formatPercent,
  timeAgo,
} from '../utils/dashboardFormat.js';
import OutgoingProductsShowcase from '../components/OutgoingProductsShowcase.jsx';
import KpiSummaryModal from '../components/KpiSummaryModal.jsx';
import GrowthChart from '../components/GrowthChart.jsx';

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
      className={`kpi-card kpi-card--clickable kpi-card--tone-${iconClass}`}
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
    <div className="sd-activity-table-wrap">
      <table className="sd-activity-table">
        <thead>
          <tr>
            <th>Document</th>
            <th>Customer</th>
            <th>Amount</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          {activities.map((act) => {
            const isOrder = act.type === 'order';
            return (
              <tr key={`${act.type}-${act.ref_number}-${act.created_at}`}>
                <td>
                  <span className={`sd-activity-icon${isOrder ? ' is-order' : ''}`}>
                    <i className={`fas ${isOrder ? 'fa-file-invoice' : 'fa-file-invoice-dollar'}`} />
                  </span>
                  <a className="sd-activity-ref" href={act.url}>{act.ref_number}</a>
                </td>
                <td className="sd-activity-customer">{act.customer_name}</td>
                <td className="sd-activity-amount">{formatMoney(act.total_amount)}</td>
                <td className="sd-activity-date">{timeAgo(act.created_at)}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function leaderboardTargetLabel(amount) {
  const target = Number(amount || 0);
  if (target >= 1_000_000) return `${Math.round(target / 1_000_000)}M`;
  return formatNumber(target);
}

function Leaderboard({ rows }) {
  if (!rows?.length) {
    return <div className="text-center text-muted small py-3">No data yet</div>;
  }

  return (
    <>
      {rows.map((rep, index) => {
        const rowTarget = Number(rep.target || 0);
        const hitTarget = rowTarget > 0 && rep.total_sold >= rowTarget;
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
                  {rowTarget > 0 ? (
                    <>
                      <span className="text-muted small">{formatPercent(rep.progress_percent)}%</span>
                      <span className="text-muted small ms-2">
                        of
                        {' '}
                        {leaderboardTargetLabel(rowTarget)}
                      </span>
                    </>
                  ) : (
                    <span className="text-muted small">Target not set</span>
                  )}
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
  const progress = Math.min(100, Math.max(0, percent));

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
        <h3 className="dash-card-title mb-2">Yearly Sales Target</h3>
        {hitTarget ? (
          <span className="yearly-congrats-badge">
            <Trophy size={13} strokeWidth={2.5} aria-hidden="true" />
            Target reached!
          </span>
        ) : null}
      </div>
      <div className="sd-yearly-summary">
        <div
          className={`sd-yearly-ring${hitTarget ? ' is-complete' : ''}`}
          style={{ '--yearly-progress': `${progress}%` }}
          role="img"
          aria-label={`${formatPercent(progress)} percent of yearly sales target achieved`}
        >
          <span>{formatPercent(progress)}%</span>
        </div>
        <div className="sd-yearly-values">
          <div className="sd-yearly-value-row">
            <span>Achieved</span>
            <strong>{formatMoney(sales)}</strong>
          </div>
          <div className="sd-yearly-value-row">
            <span>Target</span>
            <strong>{formatMoney(target)}</strong>
          </div>
          <div className="sd-yearly-value-row sd-yearly-remaining">
            <span>Remaining</span>
            <strong>{formatMoney(Math.max(0, target - sales))}</strong>
          </div>
        </div>
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
  const summaries = data.kpi_summaries || {};
  const activeSummary = activeKpiKey ? summaries[activeKpiKey] : null;

  return (
    <>
      <FlashAlerts flash={data.flash} />

      <div className="kpi-overview">
        <KpiCard
          icon={<Handshake size={14} />}
          iconClass="green"
          title={data.user?.is_admin ? 'Total Monthly Sales' : 'Monthly Sales'}
          value={formatMoney(metrics.monthly_sales_display)}
          onClick={() => setActiveKpiKey('monthly_sales_scope')}
          subtext={data.user?.is_admin ? 'All sales this month' : 'Your sales this month'}
        />
        <KpiCard
          icon={<DollarSign size={14} />}
          iconClass="blue"
          title="Quotation Value"
          value={formatMoney(metrics.quotation_value)}
          onClick={() => setActiveKpiKey('quotation_value')}
          subtext="This month"
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
      </div>

      <KpiSummaryModal
        summary={activeSummary}
        onClose={() => setActiveKpiKey(null)}
      />

      <div className="row g-3 mb-3 sd-dashboard-main-row">
        <div className="col-xl-8 col-lg-7">
          <div className="dash-card sd-revenue-growth-card">
            <GrowthChart
              title="Sales Growth"
              series={data.revenue_growth || {}}
              comparisonSeries={data.quote_growth || {}}
              seriesLabel="Sales Revenue"
              comparisonLabel="Quotation Value"
              theme="quote"
              ariaLabel="Sales revenue compared with quotation value"
            />
          </div>
        </div>
        <div className="col-xl-4 col-lg-5">
          <YearlyTarget yearly={data.yearly || {}} />
        </div>
      </div>

      <div className="row g-3 sd-dashboard-bottom-row align-items-stretch">
        <div className="col-xl-7 col-lg-7">
          <div className="dash-card sd-recent-activity-card">
            <div className="sd-section-card-head">
              <h3 className="dash-card-title">
                <i className="fas fa-clock-rotate-left me-2" aria-hidden="true" />
                Recent Activity
              </h3>
              {data.urls?.orders ? (
                <a className="sd-activity-view-all" href={data.urls.orders}>
                  View all <ArrowRight size={14} />
                </a>
              ) : null}
            </div>
            <RecentActivity activities={data.recent_activities} />
          </div>
        </div>
        <div className="col-xl-5 col-lg-5">
          <div className="dash-card sd-leaderboard-card sd-card-scroll">
            <div className="leaderboard-header">
              <h3 className="dash-card-title">Leaderboard</h3>
              <span className="leaderboard-subtitle">Total Sales</span>
            </div>
            <Leaderboard rows={data.leaderboard} />
          </div>
        </div>
      </div>

      <div className="row g-3 sd-dashboard-products-row">
        <div className="col-12">
          <OutgoingProductsShowcase
            products={data.most_outgoing_products}
            title="Most Outgoing Products"
            lookbackDays={data.most_sold_lookback_days}
            emptyMessage={data.is_roadmaster ? 'No truck or spare sales yet' : 'No product data yet'}
            placeholderIcon="fa-box"
            viewAllUrl={data.urls?.statistics || ''}
            productViewUrl={data.urls?.product_view || ''}
            compact
            maxFanProducts={0}
          />
        </div>
      </div>
    </>
  );
}
