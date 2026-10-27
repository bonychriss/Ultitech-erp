import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Plus, X, ChevronLeft, ChevronRight } from 'lucide-react';
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
import { fetchInit, fetchRecentTransactions } from '../api';
import type { DashboardInit, InsightItem, RecentTransactionRow } from '../types';
import { navigateLdView } from '../nav';

const RECENT_TX_PER_PAGE = 100;

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
  const [manualOpen, setManualOpen] = useState(() => {
    try {
      return new URLSearchParams(window.location.search).get('manual') === '1';
    } catch {
      return false;
    }
  });
  const [insightsExpanded, setInsightsExpanded] = useState(false);
  const [insightSlide, setInsightSlide] = useState(0);
  const [extraInsights, setExtraInsights] = useState<InsightItem[]>([]);
  const [extraLoading, setExtraLoading] = useState(false);
  const [recentTransactions, setRecentTransactions] = useState<RecentTransactionRow[]>([]);
  const [txPage, setTxPage] = useState(1);
  const [txTotalPages, setTxTotalPages] = useState(1);
  const [txTotal, setTxTotal] = useState(0);
  const [txLoading, setTxLoading] = useState(false);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const init = await fetchInit();
        if (!cancelled) {
          setData(init);
          setRecentTransactions(Array.isArray(init.recentTransactions) ? init.recentTransactions : []);
          const meta = init.recentTransactionsMeta;
          setTxPage(meta?.page ?? 1);
          setTxTotalPages(meta?.totalPages ?? 1);
          setTxTotal(meta?.total ?? (Array.isArray(init.recentTransactions) ? init.recentTransactions.length : 0));
        }
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
    if (!manualOpen) return;
    try {
      const url = new URL(window.location.href);
      if (url.searchParams.get('manual') === '1') {
        url.searchParams.delete('manual');
        const next = `${url.pathname}${url.search}${url.hash}`;
        window.history.replaceState({}, '', next);
      }
    } catch {
      /* ignore */
    }
  }, [manualOpen]);

  function closeManual() {
    setManualOpen(false);
  }

  async function loadTxPage(page: number) {
    if (page < 1 || page > txTotalPages || page === txPage || txLoading) return;
    setTxLoading(true);
    try {
      const payload = await fetchRecentTransactions(page, RECENT_TX_PER_PAGE);
      setRecentTransactions(payload.transactions);
      setTxPage(payload.page);
      setTxTotalPages(payload.totalPages);
      setTxTotal(payload.total);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load transactions.');
    } finally {
      setTxLoading(false);
    }
  }

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

  const rotatingInsights = useMemo(() => {
    // Primary curated list (visible) already filters filler; rotate those first.
    // Important alerts may be fewer than 3; otherwise backend pads to ≥3.
    const visible = data?.insights.visible ?? [];
    if (visible.length > 0) return visible;
    const hidden = data?.insights.hidden ?? [];
    if (hidden.length > 0) return hidden;
    return [
      {
        label: 'Tip',
        class: 'tip',
        text: 'Post transactions to see liquidity insights here.',
        link: '',
      } satisfies InsightItem,
    ];
  }, [data?.insights.visible, data?.insights.hidden]);

  useEffect(() => {
    setInsightSlide(0);
  }, [rotatingInsights.length]);

  useEffect(() => {
    if (insightsExpanded || rotatingInsights.length <= 1) return;
    const timer = window.setInterval(() => {
      setInsightSlide((current) => (current + 1) % rotatingInsights.length);
    }, 5000);
    return () => window.clearInterval(timer);
  }, [insightsExpanded, rotatingInsights.length]);

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
  const transactionsUrl = typeof data.transactionsUrl === 'string' ? data.transactionsUrl : '';
  const txFrom = txTotal === 0 ? 0 : (txPage - 1) * RECENT_TX_PER_PAGE + 1;
  const txTo = Math.min(txPage * RECENT_TX_PER_PAGE, txTotal);
  const activeInsight = rotatingInsights[insightSlide % rotatingInsights.length] ?? rotatingInsights[0];

  return (
    <div className="ld-dash">
      <div className="ld-header">
        <div>
          <h1>Liquidity Dashboard</h1>
        </div>
        {data.canManageAccount ? (
          <div className="ld-header-actions">
            <a
              href={data.coaCreateUrl}
              className="ld-btn ld-btn--purple ld-btn--icon"
              title="Create account"
              aria-label="Create account"
            >
              <Plus className="w-5 h-5" aria-hidden="true" />
            </a>
          </div>
        ) : null}
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
            </h3>
            {insights.aiConnected ? (
              <span className="ld-live-pill">Live</span>
            ) : (
              <span className="ld-connected is-offline">Offline</span>
            )}
          </div>
          <div className="ld-card-b ld-insights-body">
            {!insightsExpanded ? (
              <div className="ld-insights-rotate" aria-live="polite">
                <div key={insightSlide} className="ld-insights-rotate-slide">
                  <InsightRow item={activeInsight} />
                </div>
                {rotatingInsights.length > 1 ? (
                  <div className="ld-insights-dots" role="tablist" aria-label="Insight slides">
                    {rotatingInsights.map((_, index) => (
                      <button
                        key={`dot-${index}`}
                        type="button"
                        role="tab"
                        aria-selected={index === insightSlide}
                        aria-label={`Show insight ${index + 1}`}
                        className={`ld-insights-dot${index === insightSlide ? ' is-active' : ''}`}
                        onClick={() => setInsightSlide(index)}
                      />
                    ))}
                  </div>
                ) : null}
              </div>
            ) : (
              <div className="ld-insights-more is-open">
                {[...insights.visible, ...insights.hidden].map((item, index) => (
                  <InsightRow key={`all-${index}`} item={item} />
                ))}
                {extraInsights.map((item, index) => (
                  <InsightRow key={`ai-${index}`} item={item} />
                ))}
                {extraLoading && <div className="ld-insights-note">Analyzing payments and liquidity...</div>}
              </div>
            )}
            {(hiddenCount > 0 || rotatingInsights.length > 1 || insights.aiConnected) && (
              <button
                type="button"
                className="ld-insights-toggle"
                onClick={() => setInsightsExpanded((v) => !v)}
              >
                {insightsExpanded
                  ? 'Show less'
                  : `Show all insights (${insights.visible.length + insights.hidden.length})`}
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
              <HiOutlineClipboardList size={18} aria-hidden="true" /> Transactions
            </h3>
            {transactionsUrl ? (
              <a href={transactionsUrl} className="ld-card-link">
                Open ledger →
              </a>
            ) : null}
          </div>
          <div className={`ld-card-b ld-card-b--flush${txLoading ? ' is-tx-loading' : ''}`}>
            {recentTransactions.length === 0 ? (
              <div className="ld-empty">No transactions yet.</div>
            ) : (
              <>
                <div className="ld-recent-cards" aria-label="Recent transactions">
                  {recentTransactions.map((tx) => {
                    const openTx = () =>
                      navigateLdView({
                        name: 'transaction',
                        accountId: tx.accountId,
                        ym: tx.ym,
                        txId: tx.id,
                      });
                    const accountLine = [tx.accountName, tx.referenceLabel].filter(Boolean).join(' · ') || '—';
                    return (
                      <button
                        key={`m-${tx.id}`}
                        type="button"
                        className="ld-recent-card"
                        onClick={openTx}
                      >
                        <div className="ld-recent-card-top">
                          <span className="ld-recent-desc-cell">
                            <span
                              className={`ld-recent-ico${tx.type === 'debit' ? ' is-out' : ' is-in'}`}
                              aria-label={tx.typeLabel || (tx.type === 'debit' ? 'Money out' : 'Money in')}
                              title={tx.type === 'debit' ? 'Out' : 'In'}
                            >
                              {tx.type === 'debit' ? (
                                <HiOutlineArrowUpRight size={17} aria-hidden="true" />
                              ) : (
                                <HiOutlineArrowDownLeft size={17} aria-hidden="true" />
                              )}
                            </span>
                            <span className="ld-recent-desc">{tx.description}</span>
                          </span>
                          <span className={`ld-recent-amt${tx.type === 'debit' ? ' is-out' : ' is-in'}`}>
                            {tx.amountDisplay}
                          </span>
                        </div>
                        <div className="ld-recent-card-meta">
                          <div className="ld-recent-card-row">
                            <span className="ld-recent-card-label">Account</span>
                            <span className="ld-recent-card-value">{accountLine}</span>
                          </div>
                          <div className="ld-recent-card-row">
                            <span className="ld-recent-card-label">Date</span>
                            <span className="ld-recent-card-value">{tx.dateLabel || '—'}</span>
                          </div>
                        </div>
                      </button>
                    );
                  })}
                </div>
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
                                aria-label={tx.typeLabel || (tx.type === 'debit' ? 'Money out' : 'Money in')}
                                title={tx.type === 'debit' ? 'Out' : 'In'}
                              >
                                {tx.type === 'debit' ? (
                                  <HiOutlineArrowUpRight size={17} aria-hidden="true" />
                                ) : (
                                  <HiOutlineArrowDownLeft size={17} aria-hidden="true" />
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
                {txTotalPages > 1 && (
                  <div className="ld-tx-pager">
                    <span className="ld-tx-pager-meta">
                      Showing {txFrom}–{txTo} of {txTotal}
                    </span>
                    <div className="ld-tx-pager-bar" role="navigation" aria-label="Transactions pages">
                      <button
                        type="button"
                        className="ld-tx-pager-nav"
                        disabled={txPage <= 1 || txLoading}
                        aria-label="Previous page"
                        onClick={() => loadTxPage(txPage - 1)}
                      >
                        <ChevronLeft size={16} strokeWidth={2.5} aria-hidden="true" />
                      </button>
                      {(() => {
                        const windowSize = Math.min(5, txTotalPages);
                        const start = Math.max(1, Math.min(txPage - 2, txTotalPages - windowSize + 1));
                        const end = start + windowSize - 1;
                        const items: (number | 'gap')[] = [];
                        for (let p = start; p <= end; p += 1) items.push(p);
                        if (end < txTotalPages) items.push('gap');
                        return items.map((item, idx) =>
                          item === 'gap' ? (
                            <span key={`gap-${idx}`} className="ld-tx-pager-gap">
                              ...
                            </span>
                          ) : (
                            <button
                              key={item}
                              type="button"
                              className={`ld-tx-pager-page${item === txPage ? ' is-active' : ''}`}
                              aria-current={item === txPage ? 'page' : undefined}
                              disabled={txLoading}
                              onClick={() => loadTxPage(item)}
                            >
                              {item}
                            </button>
                          ),
                        );
                      })()}
                      <button
                        type="button"
                        className="ld-tx-pager-nav is-next"
                        disabled={txPage >= txTotalPages || txLoading}
                        aria-label="Next page"
                        onClick={() => loadTxPage(txPage + 1)}
                      >
                        <ChevronRight size={16} strokeWidth={2.5} aria-hidden="true" />
                      </button>
                    </div>
                  </div>
                )}
              </>
            )}
          </div>
        </section>
      </div>

      {manualOpen && (
        <div className="ld-modal-backdrop" role="presentation" onClick={closeManual}>
          <div
            className="ld-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ld-manual-title"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="ld-modal-h">
              <h2 id="ld-manual-title">Balances &amp; liquidity user manual</h2>
              <button type="button" className="ld-modal-x" onClick={closeManual} aria-label="Close">
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
