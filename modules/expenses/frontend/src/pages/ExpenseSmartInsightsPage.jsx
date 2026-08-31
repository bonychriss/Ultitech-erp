import { useEffect, useState } from 'react';
import { Calendar, CircleDollarSign, Clock, Loader2, Receipt, TrendingUp } from 'lucide-react';
import {
  Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { deskPageUrl, fetchInsightsStats } from '../api/expensesDesk';

const CHART_COLORS = ['#4f46e5', '#059669', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d'];

function formatCurrency(value, currencyCode = 'TZS') {
  const code = String(currencyCode || 'TZS').replace(/^TSh$/i, 'TZS');
  const amount = Number(value) || 0;
  return `${code} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function StatCard({ label, value, sub, icon }) {
  return (
    <article className="exp-desk-kpi exp-desk-insights-stat">
      <div className="exp-desk-kpi-icon exp-desk-kpi-icon--indigo">{icon}</div>
      <div className="exp-desk-kpi-body">
        <div className="exp-desk-kpi-label">{label}</div>
        <div className="exp-desk-kpi-value exp-desk-kpi-value--money">{value}</div>
        {sub && <div className="exp-desk-kpi-helper">{sub}</div>}
      </div>
    </article>
  );
}

export default function ExpenseSmartInsightsPage() {
  const [selectedMonth, setSelectedMonth] = useState(new Date().toISOString().slice(0, 7));
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError('');
    fetchInsightsStats(selectedMonth)
      .then((data) => {
        if (!cancelled) setStats(data);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Failed to load insights.');
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [selectedMonth]);

  const monthLabel = stats?.current_month_label
    || new Date(`${selectedMonth}-01T12:00:00`).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

  if (loading && !stats) {
    return (
      <div className="exp-desk-page exp-desk-boot-loading" role="status" aria-live="polite">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading insights...</span>
      </div>
    );
  }

  return (
    <div className="exp-desk-page exp-desk-insights-page">
      <div className="exp-desk-insights-toolbar">
        <p className="exp-desk-insights-lead">
          Charts and summaries for posted expenses. Use the month filter to focus on a specific period.
        </p>
        <label className="exp-desk-insights-month">
          <span>Month</span>
          <input
            type="month"
            value={selectedMonth}
            onChange={(event) => setSelectedMonth(event.target.value)}
          />
        </label>
      </div>

      {error && (
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error}</div>
      )}

      {stats && (
        <>
          <section className="exp-desk-kpi-grid exp-desk-insights-kpi-grid" aria-label="Summary">
            <StatCard
              label="monthly spend"
              value={formatCurrency(stats.spend_month)}
              sub={monthLabel}
              icon={<Calendar size={20} aria-hidden="true" />}
            />
            <StatCard
              label="posted this month"
              value={String(stats.posted_month_count ?? 0)}
              sub="Posted to accounts"
              icon={<Receipt size={20} aria-hidden="true" />}
            />
            <StatCard
              label="total volume"
              value={formatCurrency(stats.total_volume)}
              sub="All posted expenses"
              icon={<CircleDollarSign size={20} aria-hidden="true" />}
            />
            <StatCard
              label="pending approval"
              value={String(stats.pending_count ?? 0)}
              sub="Awaiting review"
              icon={<Clock size={20} aria-hidden="true" />}
            />
          </section>

          <section className="exp-desk-insights-charts">
            <article className="exp-desk-insights-card exp-desk-insights-card--wide">
              <div className="exp-desk-insights-card-head">
                <div>
                  <h2 className="exp-desk-insights-card-title">Spending trend</h2>
                  <p className="exp-desk-insights-card-sub">Posted expenses over the last 6 months</p>
                </div>
                <TrendingUp size={18} aria-hidden="true" />
              </div>
              <div className="exp-desk-insights-chart">
                {stats.trends?.length ? (
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={stats.trends}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e2e8f0" />
                      <XAxis dataKey="name" axisLine={false} tickLine={false} tick={{ fill: '#64748b', fontSize: 12 }} />
                      <YAxis axisLine={false} tickLine={false} tick={{ fill: '#64748b', fontSize: 11 }} />
                      <Tooltip formatter={(value) => [formatCurrency(value), 'Spent']} />
                      <Bar dataKey="amount" fill="#4f46e5" radius={[8, 8, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                ) : (
                  <p className="exp-desk-insights-empty">No posted spending in the last 6 months.</p>
                )}
              </div>
            </article>

            <article className="exp-desk-insights-card">
              <div className="exp-desk-insights-card-head">
                <div>
                  <h2 className="exp-desk-insights-card-title">By category</h2>
                  <p className="exp-desk-insights-card-sub">{monthLabel}</p>
                </div>
              </div>
              <div className="exp-desk-insights-chart exp-desk-insights-chart--compact">
                {stats.by_category?.length ? (
                  <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                      <Pie data={stats.by_category} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius={42} outerRadius={72} paddingAngle={3}>
                        {stats.by_category.map((entry, index) => (
                          <Cell key={`${entry.name}-${index}`} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                        ))}
                      </Pie>
                      <Tooltip formatter={(value) => formatCurrency(value)} />
                      <Legend />
                    </PieChart>
                  </ResponsiveContainer>
                ) : (
                  <p className="exp-desk-insights-empty">No category spend for this month.</p>
                )}
              </div>
            </article>

            <article className="exp-desk-insights-card">
              <div className="exp-desk-insights-card-head">
                <div>
                  <h2 className="exp-desk-insights-card-title">By status</h2>
                  <p className="exp-desk-insights-card-sub">All saved expenses</p>
                </div>
              </div>
              <div className="exp-desk-insights-chart exp-desk-insights-chart--compact">
                {stats.by_status?.length ? (
                  <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                      <Pie data={stats.by_status} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius={42} outerRadius={72} paddingAngle={3}>
                        {stats.by_status.map((entry, index) => (
                          <Cell key={`${entry.name}-${index}`} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                        ))}
                      </Pie>
                      <Tooltip />
                      <Legend />
                    </PieChart>
                  </ResponsiveContainer>
                ) : (
                  <p className="exp-desk-insights-empty">No expenses to chart yet.</p>
                )}
              </div>
            </article>
          </section>

          <div className="exp-desk-insights-footer">
            <a className="exp-desk-btn exp-desk-btn-create" href={deskPageUrl('index.php')}>
              Back to expenses
            </a>
            <a className="exp-desk-btn exp-desk-btn-export" href={deskPageUrl('create.php')}>
              Record expense
            </a>
          </div>
        </>
      )}
    </div>
  );
}
