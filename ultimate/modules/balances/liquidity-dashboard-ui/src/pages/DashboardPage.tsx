import { useEffect, useState, type ReactNode } from 'react';
import { Plus, CircleHelp, X } from 'lucide-react';
import {
  HiOutlineShieldCheck,
  HiOutlineCash,
  HiOutlineOfficeBuilding,
  HiOutlineDeviceMobile,
  HiOutlineSparkles,
  HiOutlineClipboardList,
  HiOutlineChartBar,
  HiOutlineChartPie,
  HiOutlineExclamation,
  HiOutlineCreditCard,
  HiChevronRight,
} from 'react-icons/hi';
import { HiOutlineArrowDownLeft, HiOutlineArrowUpRight } from 'react-icons/hi2';
import { fetchInit } from '../api';
import type { DashboardInit, InsightItem } from '../types';
import { navigateLdView } from '../nav';

/** Semantic themes: success=green, danger=red, warning=amber, info=blue, tip=slate, payment=indigo, ai=violet */
function insightTone(item: InsightItem): string {
  const raw = String(item.class || '').toLowerCase();
  if (['success', 'highlight'].includes(raw)) return 'success';
  if (['danger', 'alert'].includes(raw)) return 'danger';
  if (raw === 'warning') return 'warning';
  if (['info', 'mix'].includes(raw)) return 'info';
  if (raw === 'payment') return 'payment';
  if (raw === 'tip') return 'tip';
  if (raw === 'ai') return 'ai';
  const text = item.text.toLowerCase();
  if (text.includes('outflow') || text.includes('overdue') || text.includes('failed')) return 'danger';
  if (text.includes('due') || text.includes('pending') || text.includes('zero balance')) return 'warning';
  if (text.includes('diversif') || text.includes('account type') || text.includes('add a ')) return 'info';
  if (text.includes('available across') || text.includes('net inflow') || text.includes('balanced')) return 'success';
  return 'tip';
}

function insightTitle(item: InsightItem): string {
  if (item.label && item.label !== 'Summary' && item.label !== 'Suggestion') return item.label;
  const text = item.text.toLowerCase();
  if (text.includes('available across') || text.includes('total liquidity')) return 'Liquidity';
  if (text.includes('diversif') || text.includes('account type')) return 'Account mix';
  if (text.includes('net inflow') || text.includes('net outflow') || text.includes('balanced')) return 'Cash flow';
  return item.label || 'Insight';
}

function insightIcon(item: InsightItem, tone: string): ReactNode {
  const title = insightTitle(item).toLowerCase();
  if (tone === 'payment' || title.includes('payment')) return <HiOutlineCreditCard size={16} />;
  if (tone === 'danger' || tone === 'warning') return <HiOutlineExclamation size={16} />;
  if (title.includes('mix') || title.includes('account')) return <HiOutlineChartPie size={16} />;
  if (title.includes('liquidity') || title.includes('cash flow') || tone === 'success') return <HiOutlineChartBar size={16} />;
  return <HiOutlineSparkles size={16} />;
}

function InsightRow({ item }: { item: InsightItem }) {
  const tone = insightTone(item);
  const body = item.link ? <a href={item.link}>{item.text}</a> : item.text;
  return (
    <div className={`ld-insight-row ld-insight-row--${tone}`}>
      <span className="ld-insight-ico" aria-hidden="true">
        {insightIcon(item, tone)}
      </span>
      <div className="ld-insight-copy">
        <span className="ld-insight-kicker">{insightTitle(item)}</span>
        <span className="ld-insight-text">{body}</span>
      </div>
      <HiChevronRight size={15} className="ld-insight-chevron" aria-hidden="true" />
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

  const { kpis, insights } = data;
  const hiddenCount = insights.hiddenCount;
  const recentTransactions = Array.isArray(data.recentTransactions) ? data.recentTransactions : [];
  const transactionsUrl = typeof data.transactionsUrl === 'string' ? data.transactionsUrl : '';

  return (
    <div className="ld-dash">
      <div className="ld-header">
        <div>
          <div className="ld-kicker">Balances</div>
          <h1>Liquidity Dashboard</h1>
          <p>Real-time view of your organization&apos;s liquidity and account balances.</p>
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
        <button
          type="button"
          className="ld-kpi-card ld-kpi-card--clickable"
          onClick={() => navigateLdView({ name: 'liquidity' })}
          title="Open Total Liquidity details"
        >
          <div className="ld-kpi-icon bg-blue-50 text-blue-600">
            <HiOutlineShieldCheck size={18} aria-hidden="true" />
          </div>
          <div className="ld-kpi-body">
            <div className="ld-kpi-label">Total Liquidity</div>
            <div className="ld-kpi-value">{kpis.totalLiquidityDisplay}</div>
            <div className="ld-kpi-sub">
              {kpis.accountCount} active account{kpis.accountCount === 1 ? '' : 's'}
            </div>
          </div>
        </button>
        <button
          type="button"
          className="ld-kpi-card ld-kpi-card--clickable"
          onClick={() => navigateLdView({ name: 'liquidity', bucket: 'cash' })}
          title="Open Cash on Hand details"
        >
          <div className="ld-kpi-icon bg-green-50 text-green-600">
            <HiOutlineCash size={18} aria-hidden="true" />
          </div>
          <div className="ld-kpi-body">
            <div className="ld-kpi-label">Cash on Hand</div>
            <div className="ld-kpi-value">{kpis.cashTotalDisplay}</div>
            <div className="ld-kpi-sub">{kpis.hasCash ? 'Physical cash' : 'No cash accounts'}</div>
          </div>
        </button>
        <button
          type="button"
          className="ld-kpi-card ld-kpi-card--clickable"
          onClick={() => navigateLdView({ name: 'liquidity', bucket: 'bank' })}
          title="Open Bank Accounts details"
        >
          <div className="ld-kpi-icon bg-indigo-50 text-indigo-600">
            <HiOutlineOfficeBuilding size={18} aria-hidden="true" />
          </div>
          <div className="ld-kpi-body">
            <div className="ld-kpi-label">Bank Accounts</div>
            <div className="ld-kpi-value">{kpis.bankTotalDisplay}</div>
            <div className="ld-kpi-sub">{kpis.hasBank ? 'Bank balances' : 'No bank accounts'}</div>
          </div>
        </button>
        <button
          type="button"
          className="ld-kpi-card ld-kpi-card--clickable"
          onClick={() => navigateLdView({ name: 'liquidity', bucket: 'mobile' })}
          title="Open Mobile Money details"
        >
          <div className="ld-kpi-icon bg-violet-50 text-violet-600">
            <HiOutlineDeviceMobile size={18} aria-hidden="true" />
          </div>
          <div className="ld-kpi-body">
            <div className="ld-kpi-label">Mobile Money</div>
            <div className={`ld-kpi-value${kpis.mobileTotal < 0 ? ' is-neg' : ''}`}>{kpis.mobileTotalDisplay}</div>
            <div className="ld-kpi-sub">{kpis.hasMobile ? 'Mobile wallets' : 'No mobile accounts'}</div>
          </div>
        </button>
      </div>

      <div className="ld-grid ld-grid--single">
        <section className="ld-card">
          <div className="ld-card-h">
            <h3>
              <HiOutlineSparkles size={18} className="text-violet-500" aria-hidden="true" /> AI Insights
              {insights.aiConnected ? <span className="ld-live-pill">Live</span> : null}
            </h3>
            {insights.aiConnected ? (
              <span className="ld-connected">
                <span className="ld-connected-dot" aria-hidden="true" /> Connected
              </span>
            ) : (
              <span className="ld-connected is-offline">Offline</span>
            )}
          </div>
          <div className="ld-card-b ld-insights-body">
            {insights.visible.length === 0 && insights.hidden.length === 0 && (
              <div className="ld-insight-row ld-insight-row--tip">
                <span className="ld-insight-ico" aria-hidden="true">
                  <HiOutlineSparkles size={16} />
                </span>
                <div className="ld-insight-copy">
                  <span className="ld-insight-kicker">Tip</span>
                  <span className="ld-insight-text">Post transactions to see liquidity insights here.</span>
                </div>
              </div>
            )}
            {insights.visible.map((item, index) => (
              <InsightRow key={`v-${index}`} item={item} />
            ))}
            {hiddenCount > 0 && (
              <div className={`ld-insights-more${insightsExpanded ? ' is-open' : ''}`}>
                {insights.hidden.map((item, index) => (
                  <InsightRow key={`h-${index}`} item={item} />
                ))}
                {extraInsights.map((item, index) => (
                  <InsightRow key={`ai-${index}`} item={item} />
                ))}
                {extraLoading && <div className="ld-insights-note">Analyzing payments and liquidity...</div>}
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
            {!insights.aiConnected && (
              <p className="ld-insights-note">Connect AI in system settings for live AI recommendations.</p>
            )}
          </div>
        </section>

        <section className="ld-card">
          <div className="ld-card-h">
            <h3>
              <HiOutlineClipboardList size={18} aria-hidden="true" /> Recent Transactions
            </h3>
            {transactionsUrl ? (
              <a href={transactionsUrl} className="ld-card-link">
                View all →
              </a>
            ) : null}
          </div>
          <div className="ld-card-b ld-card-b--flush">
            {recentTransactions.length === 0 ? (
              <div className="ld-empty">No recent transactions yet.</div>
            ) : (
              <div className="ld-recent-table-wrap">
                <table className="ld-recent-table">
                  <thead>
                    <tr>
                      <th>Description</th>
                      <th>Account / Category</th>
                      <th>Date</th>
                      <th className="ld-recent-amt-h">Amount</th>
                    </tr>
                  </thead>
                  <tbody>
                    {recentTransactions.map((tx) => (
                      <tr
                        key={tx.id}
                        tabIndex={0}
                        onClick={() =>
                          navigateLdView({
                            name: 'transaction',
                            accountId: tx.accountId,
                            ym: tx.ym,
                            txId: tx.id,
                          })
                        }
                        onKeyDown={(e) => {
                          if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            navigateLdView({
                              name: 'transaction',
                              accountId: tx.accountId,
                              ym: tx.ym,
                              txId: tx.id,
                            });
                          }
                        }}
                      >
                        <td>
                          <span className="ld-recent-desc-cell">
                            <span
                              className={`ld-recent-ico${tx.type === 'debit' ? ' is-out' : ' is-in'}`}
                              aria-label={tx.typeLabel}
                              title={tx.type === 'debit' ? 'Out' : 'In'}
                            >
                              {tx.type === 'debit' ? (
                                <HiOutlineArrowUpRight size={14} aria-hidden="true" />
                              ) : (
                                <HiOutlineArrowDownLeft size={14} aria-hidden="true" />
                              )}
                            </span>
                            <span className="ld-recent-desc">{tx.description}</span>
                          </span>
                        </td>
                        <td className="ld-recent-cat">
                          {tx.accountName}
                          {tx.referenceLabel ? ` · ${tx.referenceLabel}` : ''}
                        </td>
                        <td className="ld-recent-date">{tx.dateLabel}</td>
                        <td className={`ld-recent-amt${tx.type === 'debit' ? ' is-out' : ' is-in'}`}>
                          {tx.amountDisplay}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
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
                  <strong>AI Insights:</strong> Smart summaries, payment alerts, and recommendations when AI is connected.
                </li>
                <li>
                  <strong>Recent transactions:</strong> Latest money movements across your accounts, with in/out icons.
                </li>
              </ul>
              <h3>2. AI Insights colors</h3>
              <p>Insight cards use color by purpose so you can scan status quickly:</p>
              <ul className="ld-manual-colors">
                <li>
                  <span className="ld-manual-swatch ld-manual-swatch--success" aria-hidden="true" />
                  <strong>Green</strong> — success / healthy liquidity
                </li>
                <li>
                  <span className="ld-manual-swatch ld-manual-swatch--info" aria-hidden="true" />
                  <strong>Blue</strong> — info / account mix / setup
                </li>
                <li>
                  <span className="ld-manual-swatch ld-manual-swatch--warning" aria-hidden="true" />
                  <strong>Amber</strong> — warnings / attention
                </li>
                <li>
                  <span className="ld-manual-swatch ld-manual-swatch--danger" aria-hidden="true" />
                  <strong>Red</strong> — danger / critical alerts
                </li>
                <li>
                  <span className="ld-manual-swatch ld-manual-swatch--payment" aria-hidden="true" />
                  <strong>Indigo</strong> — payment due
                </li>
                <li>
                  <span className="ld-manual-swatch ld-manual-swatch--tip" aria-hidden="true" />
                  <strong>Slate</strong> — tips
                </li>
                <li>
                  <span className="ld-manual-swatch ld-manual-swatch--ai" aria-hidden="true" />
                  <strong>Violet</strong> — AI suggestions
                </li>
              </ul>
              <h3>3. Managing accounts</h3>
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
              <h3>4. Transfers vs transactions</h3>
              <p>
                <strong>Internal transfers</strong> move money between your own accounts. The{' '}
                <strong>transactions ledger</strong> is the audit trail for all money movements.
              </p>
              <h3>5. FAQ</h3>
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
