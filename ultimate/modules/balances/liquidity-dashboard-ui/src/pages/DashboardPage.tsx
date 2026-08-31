import { useEffect, useRef, useState } from 'react';
import {
  Chart,
  LineController,
  LineElement,
  PointElement,
  LinearScale,
  CategoryScale,
  Filler,
  DoughnutController,
  ArcElement,
  BarController,
  BarElement,
  Tooltip,
  Legend,
} from 'chart.js';
import {
  Shield,
  Banknote,
  Landmark,
  Smartphone,
  Plus,
  CircleHelp,
  Bot,
  X,
} from 'lucide-react';
import { fetchInit } from '../api';
import type { DashboardInit, InsightItem } from '../types';

Chart.register(
  LineController,
  LineElement,
  PointElement,
  LinearScale,
  CategoryScale,
  Filler,
  DoughnutController,
  ArcElement,
  BarController,
  BarElement,
  Tooltip,
  Legend,
);

function moneyTick(v: string | number) {
  const n = typeof v === 'string' ? Number(v) : v;
  if (n >= 1e6) return `${(n / 1e6).toFixed(1)}M`;
  if (n >= 1e3) return `${(n / 1e3).toFixed(0)}K`;
  return String(n);
}

function InsightMsg({ item }: { item: InsightItem }) {
  return (
    <div className={`ld-ai-msg ld-ai-msg--${item.class}`}>
      <span className="ld-ai-msg-label">{item.label}</span>
      {item.link ? <a href={item.link}>{item.text}</a> : item.text}
    </div>
  );
}

export default function DashboardPage() {
  const [data, setData] = useState<DashboardInit | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [manualOpen, setManualOpen] = useState(false);
  const [insightsExpanded, setInsightsExpanded] = useState(false);
  const [extraInsights, setExtraInsights] = useState<InsightItem[]>([]);
  const [extraLoading, setExtraLoading] = useState(false);

  const flowRef = useRef<HTMLCanvasElement | null>(null);
  const statsRef = useRef<HTMLCanvasElement | null>(null);
  const topRef = useRef<HTMLCanvasElement | null>(null);
  const chartsRef = useRef<Chart[]>([]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const init = await fetchInit();
        if (!cancelled) setData(init);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load dashboard.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    if (!data) return;

    chartsRef.current.forEach((c) => c.destroy());
    chartsRef.current = [];

    const chartDefaults = {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
    };

    if (flowRef.current) {
      chartsRef.current.push(
        new Chart(flowRef.current, {
          type: 'line',
          data: {
            labels: data.trend.labels,
            datasets: [
              {
                label: 'Inflow',
                data: data.trend.credits,
                borderColor: '#16a34a',
                backgroundColor: 'rgba(22, 163, 74, 0.08)',
                fill: true,
                tension: 0.35,
                borderWidth: 2,
                pointRadius: 2,
              },
              {
                label: 'Outflow',
                data: data.trend.debits,
                borderColor: '#f87171',
                backgroundColor: 'rgba(248, 113, 113, 0.06)',
                fill: true,
                tension: 0.35,
                borderWidth: 2,
                pointRadius: 2,
              },
            ],
          },
          options: {
            ...chartDefaults,
            interaction: { mode: 'index', intersect: false },
            scales: {
              x: { grid: { display: false }, ticks: { maxTicksLimit: 8, font: { size: 10 } } },
              y: {
                beginAtZero: true,
                ticks: { font: { size: 10 }, callback: moneyTick },
              },
            },
          },
        }),
      );
    }

    if (statsRef.current) {
      chartsRef.current.push(
        new Chart(statsRef.current, {
          type: 'doughnut',
          data: {
            labels: ['Cash', 'Bank', 'Mobile'],
            datasets: [
              {
                data: [
                  data.accountStats.counts.cash,
                  data.accountStats.counts.bank,
                  data.accountStats.counts.mobile,
                ],
                backgroundColor: ['#22c55e', '#f97316', '#ef4444'],
                borderWidth: 0,
                hoverOffset: 4,
              },
            ],
          },
          options: {
            ...chartDefaults,
            cutout: '68%',
            plugins: {
              legend: { display: false },
              tooltip: { enabled: true },
            },
          },
        }),
      );
    }

    if (topRef.current) {
      chartsRef.current.push(
        new Chart(topRef.current, {
          type: 'bar',
          data: {
            labels: data.topAccounts.labels,
            datasets: [
              {
                data: data.topAccounts.values,
                backgroundColor: data.topAccounts.colors,
                borderRadius: 6,
                barThickness: 18,
              },
            ],
          },
          options: {
            ...chartDefaults,
            indexAxis: 'y',
            scales: {
              x: {
                beginAtZero: true,
                ticks: { font: { size: 10 }, callback: moneyTick },
                grid: { color: '#f1f5f9' },
              },
              y: { grid: { display: false }, ticks: { font: { size: 11 } } },
            },
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label(ctx) {
                    const i = ctx.dataIndex;
                    return data.topAccounts.displays[i] || String(ctx.raw);
                  },
                },
              },
            },
          },
        }),
      );
    }

    return () => {
      chartsRef.current.forEach((c) => c.destroy());
      chartsRef.current = [];
    };
  }, [data]);

  useEffect(() => {
    if (!data?.insights.aiConnected || !insightsExpanded || extraInsights.length > 0 || extraLoading) {
      return;
    }
    let cancelled = false;
    (async () => {
      setExtraLoading(true);
      try {
        const res = await fetch(data.aiInsightsUrl, { credentials: 'same-origin' });
        const json = (await res.json()) as {
          success?: boolean;
          suggestions?: string[];
          lines?: string[];
        };
        if (cancelled) return;
        const lines = json.suggestions || json.lines || [];
        setExtraInsights(
          lines.map((text) => ({
            label: 'AI',
            class: 'ai',
            text: String(text),
            link: '',
          })),
        );
      } catch {
        if (!cancelled) setExtraInsights([]);
      } finally {
        if (!cancelled) setExtraLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [data, insightsExpanded, extraInsights.length, extraLoading]);

  if (loading) {
    return (
      <div className="ld-loading" role="status" aria-live="polite">
        <span className="ld-loading-spinner" aria-hidden="true" />
        Loading dashboard...
      </div>
    );
  }

  if (error || !data) {
    return <div className="ld-error">{error || 'Dashboard unavailable.'}</div>;
  }

  const { kpis, insights, accountStats } = data;
  const hiddenCount = insights.hiddenCount;

  return (
    <div className="ld-dash">
      <div className="ld-header">
        <div>
          <h1>Liquidity Dashboard</h1>
        </div>
        <div className="ld-header-actions">
          {data.canManageAccount && (
            <a href={data.coaCreateUrl} className="ld-btn ld-btn--purple">
              <Plus className="w-4 h-4" aria-hidden="true" /> Create account
            </a>
          )}
          <button type="button" className="ld-btn ld-btn--outline" onClick={() => setManualOpen(true)}>
            <CircleHelp className="w-4 h-4" aria-hidden="true" /> Manual
          </button>
        </div>
      </div>

      <div className="ld-kpi-grid">
        <div className="ld-kpi-card">
          <div className="ld-kpi-icon bg-blue-50 text-blue-600">
            <Shield className="w-5 h-5" />
          </div>
          <div className="ld-kpi-value">{kpis.totalLiquidityDisplay}</div>
          <div className="ld-kpi-label">Total Liquidity</div>
          <div className="ld-kpi-sub">
            {kpis.accountCount} active account{kpis.accountCount === 1 ? '' : 's'}
          </div>
        </div>
        <div className="ld-kpi-card">
          <div className="ld-kpi-icon bg-green-50 text-green-600">
            <Banknote className="w-5 h-5" />
          </div>
          <div className="ld-kpi-value">{kpis.cashTotalDisplay}</div>
          <div className="ld-kpi-label">Cash on Hand</div>
          <div className="ld-kpi-sub">{kpis.hasCash ? 'Physical cash' : 'No cash accounts'}</div>
        </div>
        <div className="ld-kpi-card">
          <div className="ld-kpi-icon bg-indigo-50 text-indigo-600">
            <Landmark className="w-5 h-5" />
          </div>
          <div className="ld-kpi-value">{kpis.bankTotalDisplay}</div>
          <div className="ld-kpi-label">Bank Accounts</div>
          <div className="ld-kpi-sub">{kpis.hasBank ? 'Bank balances' : 'No bank accounts'}</div>
        </div>
        <div className="ld-kpi-card">
          <div className="ld-kpi-icon bg-violet-50 text-violet-600">
            <Smartphone className="w-5 h-5" />
          </div>
          <div className={`ld-kpi-value${kpis.mobileTotal < 0 ? ' is-neg' : ''}`}>{kpis.mobileTotalDisplay}</div>
          <div className="ld-kpi-label">Mobile Money</div>
          <div className="ld-kpi-sub">{kpis.hasMobile ? 'Mobile wallets' : 'No mobile accounts'}</div>
        </div>
      </div>

      <div className="ld-grid">
        <section className="ld-card">
          <div className="ld-card-h">
            <h3>Cash Flow Trend (30 days)</h3>
          </div>
          <div className="ld-card-b">
            <div className="ld-chart-wrap">
              <canvas ref={flowRef} />
            </div>
            <div className="ld-legend">
              <span>
                <span className="ld-legend-dot" style={{ background: '#16a34a' }} />
                Inflow (credits)
              </span>
              <span>
                <span className="ld-legend-dot" style={{ background: '#f87171' }} />
                Outflow (debits)
              </span>
            </div>
          </div>
        </section>

        <section className="ld-card">
          <div className="ld-card-h">
            <h3>Accounts Statistics</h3>
          </div>
          <div className="ld-card-b">
            <div className="ld-chart-wrap ld-chart-wrap--donut">
              <canvas ref={statsRef} />
              <div className="ld-donut-center">
                <span className="n">{accountStats.total.toLocaleString()}</span>
                <span className="l">Total Accounts</span>
              </div>
            </div>
            <div className="ld-legend">
              <span>
                <span className="ld-legend-dot" style={{ background: '#22c55e' }} /> Cash {accountStats.counts.cash} (
                {accountStats.pct.cash}%)
              </span>
              <span>
                <span className="ld-legend-dot" style={{ background: '#f97316' }} /> Bank {accountStats.counts.bank} (
                {accountStats.pct.bank}%)
              </span>
              <span>
                <span className="ld-legend-dot" style={{ background: '#ef4444' }} /> Mobile {accountStats.counts.mobile} (
                {accountStats.pct.mobile}%)
              </span>
            </div>
          </div>
        </section>
      </div>

      <div className="ld-grid">
        <section className="ld-card">
          <div className="ld-card-h">
            <h3>Top Accounts by Balance</h3>
          </div>
          <div className="ld-card-b">
            <div className="ld-chart-wrap ld-chart-wrap--tall">
              <canvas ref={topRef} />
            </div>
          </div>
        </section>

        <section className="ld-card">
          <div className="ld-card-h">
            <h3>
              <Bot className="w-4 h-4 text-violet-600" aria-hidden="true" /> AI Insights
            </h3>
            {insights.aiConnected && <span className="ld-live">Connected</span>}
          </div>
          <div className="ld-card-b">
            <div className="ld-insights-chat">
              <div className="ld-bot-wrap">
                <div className={`ld-bot-avatar${insights.aiConnected ? ' is-live' : ''}`} aria-hidden="true">
                  <Bot className="w-5 h-5" />
                </div>
                <span className={`ld-bot-status${insights.aiConnected ? '' : ' is-offline'}`}>
                  {insights.aiConnected ? 'Live' : 'Offline'}
                </span>
              </div>
              <div className="ld-insights-messages">
                {insights.visible.length === 0 && insights.hidden.length === 0 && (
                  <div className="ld-ai-msg ld-ai-msg--tip">
                    <span className="ld-ai-msg-label">Tip</span>
                    Post transactions to see liquidity insights here.
                  </div>
                )}
                {insights.visible.map((item, index) => (
                  <InsightMsg key={`v-${index}`} item={item} />
                ))}
                {hiddenCount > 0 && (
                  <div className={`ld-insights-more${insightsExpanded ? ' is-open' : ''}`}>
                    {insights.hidden.map((item, index) => (
                      <InsightMsg key={`h-${index}`} item={item} />
                    ))}
                    {extraInsights.map((item, index) => (
                      <InsightMsg key={`ai-${index}`} item={item} />
                    ))}
                    {extraLoading && <div className="ld-insights-note">Analyzing payments and liquidityù</div>}
                  </div>
                )}
                {hiddenCount > 0 && (
                  <button
                    type="button"
                    className="ld-insights-toggle"
                    onClick={() => setInsightsExpanded((v) => !v)}
                  >
                    {insightsExpanded ? 'Show less' : `Show all insights (${hiddenCount})`}
                  </button>
                )}
              </div>
            </div>
            {!insights.aiConnected && (
              <p className="ld-insights-note">Connect AI in system settings for live AI recommendations.</p>
            )}
          </div>
        </section>
      </div>

      {manualOpen && (
        <div className="ld-modal-backdrop" role="presentation" onClick={() => setManualOpen(false)}>
          <div
            className="ld-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ld-manual-title"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="ld-modal-h">
              <h2 id="ld-manual-title">Balances &amp; liquidity user manual</h2>
              <button type="button" className="ld-modal-x" onClick={() => setManualOpen(false)} aria-label="Close">
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="ld-modal-b">
              <p>Guide to managing your financial liquidity module.</p>
              <h3>1. Dashboard overview</h3>
              <p>The Liquidity Dashboard gives you an instant snapshot of your financial position.</p>
              <ul>
                <li>
                  <strong>Total liquidity:</strong> The sum of all money across all your active accounts.
                </li>
                <li>
                  <strong>Breakdowns:</strong> Quick cards showing totals for cash, bank, and mobile money.
                </li>
                <li>
                  <strong>Charts:</strong> 30-day cash flow trend, accounts statistics, and top accounts by balance.
                </li>
                <li>
                  <strong>AI Insights:</strong> Smart summaries, payment alerts, and recommendations when AI is connected.
                </li>
              </ul>
              <h3>2. Managing accounts</h3>
              <p>Use the Accounts page to set up where you keep money.</p>
              <ul>
                <li>
                  <strong>Admin permissions:</strong> Can create, edit, and delete accounts.
                </li>
                <li>
                  <strong>Finance team:</strong> Can create and view accounts only.
                </li>
                <li>
                  <strong>Opening balance:</strong> Enter the amount currently in the account once when creating it.
                </li>
              </ul>
              <h3>3. Transfers vs transactions</h3>
              <p>
                <strong>Internal transfers</strong> move money between your own accounts. The{' '}
                <strong>transactions ledger</strong> is the audit trail for all money movements.
              </p>
              <h3>4. FAQ</h3>
              <p>
                Record customer payments in Sales (select receiving account). Pay expenses via vouchers (select source
                account). Balances update automatically.
              </p>
            </div>
            <div className="ld-modal-f">
              <button type="button" className="ld-btn ld-btn--outline" onClick={() => setManualOpen(false)}>
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
