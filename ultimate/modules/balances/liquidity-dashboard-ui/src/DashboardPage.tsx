import { useEffect, useState } from 'react';
import { Plus, CircleHelp, X } from 'lucide-react';
import {
  HiOutlineShieldCheck,
  HiOutlineCash,
  HiOutlineOfficeBuilding,
  HiOutlineDeviceMobile,
  HiOutlineSparkles,
  HiOutlineClipboardList,
} from 'react-icons/hi';
import { fetchInit } from '../api';
import type { DashboardInit, InsightItem } from '../types';
import { navigateLdView } from '../nav';

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
        <button
          type="button"
          className="ld-kpi-card ld-kpi-card--clickable"
          onClick={() => navigateLdView({ name: 'liquidity' })}
          title="Open Total Liquidity details"
        >
          <div className="ld-kpi-icon bg-blue-50 text-blue-600">
            <HiOutlineShieldCheck size={16} aria-hidden="true" />
          </div>
          <div className="ld-kpi-body">
            <div className="ld-kpi-value">{kpis.totalLiquidityDisplay}</div>
            <div className="ld-kpi-label">Total Liquidity</div>
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
            <HiOutlineCash size={16} aria-hidden="true" />
          </div>
          <div className="ld-kpi-body">
            <div className="ld-kpi-value">{kpis.cashTotalDisplay}</div>
            <div className="ld-kpi-label">Cash on Hand</div>
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
            <HiOutlineOfficeBuilding size={16} aria-hidden="true" />
          </div>
          <div className="ld-kpi-body">
            <div className="ld-kpi-value">{kpis.bankTotalDisplay}</div>
            <div className="ld-kpi-label">Bank Accounts</div>
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
            <HiOutlineDeviceMobile size={16} aria-hidden="true" />
          </div>
          <div className="ld-kpi-body">
            <div className={`ld-kpi-value${kpis.mobileTotal < 0 ? ' is-neg' : ''}`}>{kpis.mobileTotalDisplay}</div>
            <div className="ld-kpi-label">Mobile Money</div>
            <div className="ld-kpi-sub">{kpis.hasMobile ? 'Mobile wallets' : 'No mobile accounts'}</div>
          </div>
        </button>
      </div>

      <div className="ld-grid ld-grid--single">
        <section className="ld-card">
          <div className="ld-card-h">
            <h3>
              <HiOutlineSparkles size={16} className="text-violet-600" aria-hidden="true" /> AI Insights
            </h3>
            {insights.aiConnected && <span className="ld-live">Connected</span>}
          </div>
          <div className="ld-card-b">
            <div className="ld-insights-chat">
              <div className="ld-bot-wrap">
                <div className={`ld-bot-avatar${insights.aiConnected ? ' is-live' : ''}`} aria-hidden="true">
                  <HiOutlineSparkles size={20} />
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
              </div>
            </div>
            {!insights.aiConnected && (
              <p className="ld-insights-note">Connect AI in system settings for live AI recommendations.</p>
            )}
          </div>
        </section>

        <section className="ld-card">
          <div className="ld-card-h">
            <h3>
              <HiOutlineClipboardList size={16} aria-hidden="true" /> Recent Transactions
            </h3>
            {transactionsUrl ? (
              <a href={transactionsUrl} className="ld-card-link">
                View all
              </a>
            ) : null}
          </div>
          <div className="ld-card-b ld-card-b--flush">
            {recentTransactions.length === 0 ? (
              <div className="ld-empty">No recent transactions yet.</div>
            ) : (
              <ul className="ld-recent-list">
                {recentTransactions.map((tx) => (
                  <li key={tx.id}>
                    <button
                      type="button"
                      className="ld-recent-row"
                      onClick={() =>
                        navigateLdView({
                          name: 'transaction',
                          accountId: tx.accountId,
                          ym: tx.ym,
                          txId: tx.id,
                        })
                      }
                    >
                      <div className="ld-recent-main">
                        <span className="ld-recent-desc">{tx.description}</span>
                        <span className="ld-recent-meta">
                          {tx.accountName}
                          {tx.referenceLabel ? ` · ${tx.referenceLabel}` : ''}
                          {` · ${tx.dateLabel}`}
                        </span>
                      </div>
                      <div className={`ld-recent-amt${tx.type === 'debit' ? ' is-out' : ' is-in'}`}>
                        <span className="ld-recent-side">{tx.typeLabel}</span>
                        <span>{tx.amountDisplay}</span>
                      </div>
                    </button>
                  </li>
                ))}
              </ul>
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
