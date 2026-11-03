import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { ChevronDown, ChevronUp, Loader2, Sparkles, X } from 'lucide-react';
import { fetchKpiAiConfirmation } from '../api/expensesDesk';
import { formatKpiHeadline } from '../utils/kpiTrace';

function formatCurrency(value, currencyCode) {
  const code = String(currencyCode || 'TZS').replace(/^TSh$/i, 'TZS');
  const amount = Number(value) || 0;
  return `${code} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(dateStr) {
  if (!dateStr) return '-';
  const normalized = dateStr.includes('T') ? dateStr : `${dateStr}T12:00:00`;
  return new Date(normalized).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function formatDateTimeDigits(dateStr, createdAt) {
  const base = String(dateStr || '').slice(0, 10);
  const [y, m, d] = base.split('-');
  if (!y || !m || !d) return '-';
  const time = String(createdAt || '').slice(11, 16);
  return `${d}/${m}/${y}${time ? ` ${time}` : ''}`;
}

function cellText(value) {
  const text = String(value ?? '').trim();
  if (!text || text === '\u2014' || text === '\u2013' || text === '\uFFFD') return '-';
  return text;
}

function normalizeCurrencyCode(currency) {
  return String(currency || 'TZS').replace(/^TSh$/i, 'TZS').trim() || 'TZS';
}

function sumAmountsByCurrency(items, field = 'amount') {
  const totals = new Map();
  for (const item of items) {
    const code = normalizeCurrencyCode(item.currency);
    totals.set(code, (totals.get(code) || 0) + (Number(item[field] ?? item.amount) || 0));
  }
  return [...totals.entries()].sort(([a], [b]) => a.localeCompare(b));
}

const EXPENSE_AI_KEYS = new Set([
  'postedThisMonth',
  'monthlySpend',
  'totalRecords',
  'listedNow',
  'totalExclusive',
  'inputVat',
  'totalInclusive',
  'totalBank',
  'totalCash',
  'totalPayables',
]);

function findScroller() {
  let el = document.querySelector('.exp-kpi-desk-table');
  while (el && el !== document.body) {
    const { overflowY } = getComputedStyle(el);
    if (/(auto|scroll)/.test(overflowY) && el.scrollHeight > el.clientHeight) return el;
    el = el.parentElement;
  }
  return document.scrollingElement || document.documentElement;
}

function ScrollButtons() {
  const [state, setState] = useState({ scrollable: false, atTop: true, atBottom: false });

  useEffect(() => {
    let scroller = null;
    const measure = () => {
      const el = findScroller();
      if (el !== scroller) {
        if (scroller) scroller.removeEventListener('scroll', measure);
        scroller = el;
        scroller.addEventListener('scroll', measure, { passive: true });
      }
      const max = el.scrollHeight - el.clientHeight;
      const next = {
        scrollable: max > 80,
        atTop: el.scrollTop <= 40,
        atBottom: el.scrollTop >= max - 40,
      };
      setState((prev) => (
        prev.scrollable === next.scrollable && prev.atTop === next.atTop && prev.atBottom === next.atBottom ? prev : next
      ));
    };
    measure();
    const timer = window.setInterval(measure, 400);
    window.addEventListener('resize', measure);
    window.addEventListener('scroll', measure, { passive: true });
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('resize', measure);
      window.removeEventListener('scroll', measure);
      if (scroller) scroller.removeEventListener('scroll', measure);
    };
  }, []);

  if (!state.scrollable) return null;

  const scrollTo = (toBottom) => {
    const el = findScroller();
    el.scrollTo({ top: toBottom ? el.scrollHeight : 0, behavior: 'smooth' });
  };

  return createPortal(
    <>
      {!state.atTop && (
        <button type="button" className="exp-kpi-scroll-btn exp-kpi-scroll-btn--up" onClick={() => scrollTo(false)} aria-label="Scroll to top" title="Scroll to top">
          <ChevronUp size={20} />
        </button>
      )}
      {!state.atBottom && (
        <button type="button" className="exp-kpi-scroll-btn exp-kpi-scroll-btn--down" onClick={() => scrollTo(true)} aria-label="Scroll to bottom" title="Scroll to bottom">
          <ChevronDown size={20} />
        </button>
      )}
    </>,
    document.body,
  );
}

function topEntry(items, keyFn, currency) {
  const map = new Map();
  for (const item of items) {
    if (normalizeCurrencyCode(item.currency) !== currency) continue;
    const key = String(keyFn(item) || '').trim();
    if (!key || key === '-') continue;
    map.set(key, (map.get(key) || 0) + (Number(item.amount) || 0));
  }
  const sorted = [...map.entries()].sort((a, b) => b[1] - a[1]);
  return sorted[0] || null;
}

function buildInsightSummary(items, title) {
  if (!items.length) return [];
  const totals = sumAmountsByCurrency(items);
  const primary = [...totals].sort((a, b) => b[1] - a[1])[0][0];
  const primaryItems = items.filter((i) => normalizeCurrencyCode(i.currency) === primary);
  const primaryTotal = primaryItems.reduce((s, i) => s + (Number(i.amount) || 0), 0);
  const dates = items.map((i) => String(i.date || '').slice(0, 10)).filter(Boolean).sort();
  const pct = (v) => (primaryTotal > 0 ? ` (${Math.round((v / primaryTotal) * 100)}%)` : '');
  const lines = [];

  const totalText = totals.map(([cur, amt]) => formatCurrency(amt, cur)).join(' + ');
  const period = dates.length
    ? ` from ${formatDate(dates[0])} to ${formatDate(dates[dates.length - 1])}`
    : '';
  lines.push(`${title}${period}: ${totalText} across ${items.length} ${items.length === 1 ? 'record' : 'records'}.`);

  const account = topEntry(items, (i) => i.account, primary);
  if (account) lines.push(`Top account: ${account[0]} \u2013 ${formatCurrency(account[1], primary)}${pct(account[1])}.`);

  const payee = topEntry(items, (i) => i.payee, primary);
  if (payee) lines.push(`Top payee: ${payee[0]} \u2013 ${formatCurrency(payee[1], primary)}${pct(payee[1])}.`);

  const largest = [...primaryItems].sort((a, b) => (Number(b.amount) || 0) - (Number(a.amount) || 0))[0];
  if (largest) {
    lines.push(`Largest entry: ${cellText(largest.expenseNumber)} \u2013 ${cellText(largest.payee)}, ${formatCurrency(largest.amount, primary)}.`);
  }

  const months = new Map();
  for (const item of primaryItems) {
    const m = String(item.date || '').slice(0, 7);
    if (m) months.set(m, (months.get(m) || 0) + (Number(item.amount) || 0));
  }
  const busiest = [...months.entries()].sort((a, b) => b[1] - a[1])[0];
  if (busiest && months.size > 1) {
    const label = new Date(`${busiest[0]}-01T12:00:00`).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    lines.push(`Busiest month: ${label} \u2013 ${formatCurrency(busiest[1], primary)}.`);
  }

  if (primaryItems.length) {
    lines.push(`Average per record: ${formatCurrency(primaryTotal / primaryItems.length, primary)}.`);
  }
  return lines;
}

function compactAmount(value, code) {
  const n = Number(value) || 0;
  const abs = Math.abs(n);
  const short = abs >= 1e9 ? `${(n / 1e9).toFixed(2)}B` : abs >= 1e6 ? `${(n / 1e6).toFixed(1)}M` : abs >= 1e3 ? `${(n / 1e3).toFixed(1)}K` : n.toFixed(0);
  return `${code} ${short}`;
}

function formatDuration(first, last) {
  const a = new Date(`${first}T12:00:00`);
  const b = new Date(`${last}T12:00:00`);
  const days = Math.max(1, Math.round((b - a) / 86400000) + 1);
  if (days < 45) return `${days} days`;
  const months = days / 30.44;
  return months < 24 ? `${months.toFixed(1).replace(/\.0$/, '')} months` : `${(months / 12).toFixed(1)} years`;
}

function buildCompactInsight(items, title) {
  if (!items.length) return null;
  const totals = sumAmountsByCurrency(items);
  const primary = [...totals].sort((a, b) => b[1] - a[1])[0][0];
  const primaryItems = items.filter((i) => normalizeCurrencyCode(i.currency) === primary);
  const primaryTotal = primaryItems.reduce((s, i) => s + (Number(i.amount) || 0), 0);
  const dates = items.map((i) => String(i.date || '').slice(0, 10)).filter(Boolean).sort();
  const pct = (v) => (primaryTotal > 0 ? Math.round((v / primaryTotal) * 100) : 0);

  const range = dates.length
    ? `${formatDate(dates[0])} \u2013 ${formatDate(dates[dates.length - 1])} (${formatDuration(dates[0], dates[dates.length - 1])})`
    : '';
  const extra = totals.length > 1 ? ` +${totals.length - 1} more` : '';
  const summary = `${range ? `${range} \u00b7 ` : ''}${compactAmount(primaryTotal, primary)}${extra} \u00b7 ${items.length} records`;

  const account = topEntry(items, (i) => i.account, primary);
  const payee = topEntry(items, (i) => i.payee, primary);
  const finding = [
    account ? `Top account: ${account[0]} (${pct(account[1])}%)` : '',
    payee ? `top payee: ${payee[0]} (${pct(payee[1])}%)` : '',
  ].filter(Boolean).join(' \u00b7 ') || `${title} covers ${items.length} records.`;

  let warning = '';
  const largest = [...primaryItems].sort((a, b) => (Number(b.amount) || 0) - (Number(a.amount) || 0))[0];
  const unsettled = items.filter((i) => !/^(posted|paid)$/i.test(String(i.status || '')));
  const months = new Map();
  for (const item of primaryItems) {
    const m = String(item.date || '').slice(0, 7);
    if (m) months.set(m, (months.get(m) || 0) + (Number(item.amount) || 0));
  }
  const monthKeys = [...months.keys()].sort();
  const lastM = months.get(monthKeys[monthKeys.length - 1]) || 0;
  const prevM = months.get(monthKeys[monthKeys.length - 2]) || 0;

  if (account && pct(account[1]) >= 50) {
    warning = `High concentration: ${account[0]} is ${pct(account[1])}% of spend.`;
  } else if (largest && pct(largest.amount) >= 10) {
    warning = `Large entry: ${cellText(largest.expenseNumber)} is ${pct(largest.amount)}% of the total.`;
  } else if (monthKeys.length > 1 && prevM > 0 && lastM / prevM >= 1.3) {
    warning = `Spend rose ${Math.round((lastM / prevM - 1) * 100)}% in the latest month vs the previous one.`;
  } else if (unsettled.length) {
    warning = `${unsettled.length} ${unsettled.length === 1 ? 'record is' : 'records are'} not yet posted or paid.`;
  } else if (totals.length > 1) {
    warning = `Mixed currencies (${totals.map(([c]) => c).join(', ')}) \u2013 amounts are not combined.`;
  } else {
    warning = 'No unusual patterns detected.';
  }
  return { summary, finding, warning, hasAlert: warning !== 'No unusual patterns detected.' };
}

function InsightBar({ insight, detailLines, confirmation, viaAi, aiLoading, aiError }) {
  const [open, setOpen] = useState(true);
  const [details, setDetails] = useState(false);
  return (
    <section className={`exp-ai-bar${open ? ' is-open' : ''}`} aria-label="AI Insight">
      <button type="button" className="exp-ai-bar-head" onClick={() => setOpen((v) => !v)} aria-expanded={open}>
        <Sparkles size={14} aria-hidden="true" className="exp-ai-bar-icon" />
        <strong className="exp-ai-bar-title">AI Insight</strong>
        <span className="exp-ai-bar-summary">{insight ? insight.summary : 'No records in this view.'}</span>
        {aiLoading && <Loader2 size={12} className="animate-spin" aria-hidden="true" />}
        {!aiLoading && viaAi && <span className="exp-ai-bar-badge">AI verified</span>}
        {open ? <ChevronUp size={14} aria-hidden="true" /> : <ChevronDown size={14} aria-hidden="true" />}
      </button>
      {open && insight && (
        <div className="exp-ai-bar-body">
          <p className="exp-ai-bar-line">{insight.finding}</p>
          <p className={`exp-ai-bar-line${insight.hasAlert ? ' is-warn' : ''}`}>
            <span aria-hidden="true">{insight.hasAlert ? '\u26a0 ' : '\u2713 '}</span>{insight.warning}
          </p>
          <button type="button" className="exp-ai-bar-link" onClick={() => setDetails((v) => !v)}>
            {details ? 'Hide details' : 'View details'}
          </button>
        </div>
      )}
      {open && details && (
        <div className="exp-ai-bar-details">
          <ul>{detailLines.map((line) => <li key={line}>{line}</li>)}</ul>
          {confirmation && <p>{confirmation}</p>}
          {aiError && <p role="alert" className="is-warn">{aiError}</p>}
        </div>
      )}
    </section>
  );
}

const KPI_PAGE_SIZE = 100;

function pagerPages(current, total) {
  if (total <= 6) return Array.from({ length: total }, (_, i) => i + 1);
  const pages = new Set([1, total, current - 1, current, current + 1]);
  const sorted = [...pages].filter((p) => p >= 1 && p <= total).sort((a, b) => a - b);
  const out = [];
  sorted.forEach((p, i) => {
    if (i && p - sorted[i - 1] > 1) out.push('...');
    out.push(p);
  });
  return out;
}

export default function ExpenseKpiTraceModal({
  trace,
  traceKey,
  filters = {},
  listedCount = 0,
  purchasesDesk = false,
  asPage = false,
  onClose,
}) {
  const items = Array.isArray(trace.items) ? trace.items : [];
  const amountField = purchasesDesk ? 'contribution' : 'amount';
  const amountTotals = sumAmountsByCurrency(items, amountField);
  const [confirmation, setConfirmation] = useState(trace.confirmation || '');
  const [viaAi, setViaAi] = useState(Boolean(trace.viaAi));
  const [aiLoading, setAiLoading] = useState(false);
  const [aiError, setAiError] = useState('');
  const [emptyAnimationReady, setEmptyAnimationReady] = useState(
    () => typeof customElements !== 'undefined' && customElements.get('dotlottie-wc'),
  );
  const [emptyAnimationError, setEmptyAnimationError] = useState(false);
  const [page, setPage] = useState(1);
  const insightLines = asPage ? buildInsightSummary(items, trace.title || 'Total') : [];
  const compactInsight = asPage ? buildCompactInsight(items, trace.title || 'Total') : null;
  const pageCount = Math.max(1, Math.ceil(items.length / KPI_PAGE_SIZE));
  const safePage = Math.min(page, pageCount);
  const pageStart = (safePage - 1) * KPI_PAGE_SIZE;
  const pageItems = items.slice(pageStart, pageStart + KPI_PAGE_SIZE);
  const showEmptyAnimation = !purchasesDesk && items.length === 0;

  useEffect(() => {
    if (!showEmptyAnimation || emptyAnimationReady) return undefined;

    const playerScriptUrl = 'https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.5/dist/dotlottie-wc.js';
    let script = document.querySelector(`script[src="${playerScriptUrl}"]`);
    const isNewScript = !script;
    if (!script) {
      script = document.createElement('script');
      script.type = 'module';
      script.src = playerScriptUrl;
    }

    let active = true;
    const onScriptError = () => {
      if (active) setEmptyAnimationError(true);
    };
    script.addEventListener('error', onScriptError);
    customElements.whenDefined('dotlottie-wc').then(() => {
      if (active) setEmptyAnimationReady(true);
    });
    if (isNewScript) document.head.appendChild(script);

    return () => {
      active = false;
      script.removeEventListener('error', onScriptError);
    };
  }, [showEmptyAnimation, emptyAnimationReady]);

  useEffect(() => {
    setConfirmation(trace.confirmation || '');
    setViaAi(Boolean(trace.viaAi));
    setAiError('');
    setAiLoading(false);
  }, [trace]);

  useEffect(() => {
    // Purchases KPIs use client-side confirmation; expense AI endpoint only
    // accepts a fixed allow-list and returns "Invalid KPI key" otherwise.
    if (purchasesDesk || !EXPENSE_AI_KEYS.has(traceKey)) {
      setAiLoading(false);
      setAiError('');
      setConfirmation(trace.confirmation || '');
      setViaAi(false);
      return undefined;
    }

    let cancelled = false;
    setAiLoading(true);
    setAiError('');

    fetchKpiAiConfirmation(traceKey, { listedCount, filters })
      .then((result) => {
        if (cancelled) return;
        setConfirmation(result.confirmation || trace.confirmation || '');
        setViaAi(Boolean(result.viaAi));
      })
      .catch((err) => {
        if (cancelled) return;
        setAiError(err instanceof Error ? err.message : 'AI verification failed.');
      })
      .finally(() => {
        if (!cancelled) setAiLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [traceKey, listedCount, filters, trace.confirmation, purchasesDesk]);

  useEffect(() => {
    if (asPage) return undefined;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function handleKeyDown(event) {
      if (event.key === 'Escape') onClose();
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [onClose, asPage]);

  const refLabel = purchasesDesk ? 'Purchase / Date' : 'Expense / Date';

  const dialog = (
      <div
        className={`exp-desk-modal exp-desk-kpi-trace-modal${purchasesDesk ? ' exp-desk-kpi-trace-modal--purchases' : ''}${asPage ? ' exp-desk-kpi-trace-modal--page' : ''}`}
        role={asPage ? undefined : 'dialog'}
        aria-modal={asPage ? undefined : 'true'}
        aria-labelledby="exp-kpi-trace-title"
        onClick={asPage ? undefined : (event) => event.stopPropagation()}
      >
        {!asPage && (<div className="exp-desk-modal-head">
          <div className="exp-desk-kpi-trace-head">
            <div>
              <h2 id="exp-kpi-trace-title" className="exp-desk-kpi-trace-title">{trace.title}</h2>
              <p className="exp-desk-kpi-trace-headline">{formatKpiHeadline(trace)}</p>
            </div>
          </div>
          {!asPage && (
            <button
              type="button"
              className="exp-desk-kpi-trace-close"
              onClick={onClose}
              aria-label="Close"
            >
              <X className="w-5 h-5" />
            </button>
          )}
        </div>)}

        <div className="exp-desk-modal-body exp-desk-kpi-trace-body">
          {asPage && (
            <InsightBar
              insight={compactInsight}
              detailLines={insightLines}
              confirmation={confirmation}
              viaAi={viaAi}
              aiLoading={aiLoading}
              aiError={aiError}
            />
          )}
          {!asPage && (confirmation || aiLoading) && (
            <section className="exp-desk-kpi-trace-section exp-desk-kpi-trace-section--confirm">
              <div className="exp-desk-kpi-trace-confirm-head">
                <h3 className="exp-desk-kpi-trace-section-title exp-kpi-insight-title"><Sparkles className="w-4 h-4" aria-hidden="true" />AI Insight</h3>
                {aiLoading && (
                  <span className="exp-desk-kpi-trace-ai-badge exp-desk-kpi-trace-ai-badge--loading">
                    <Loader2 className="w-3 h-3 animate-spin" aria-hidden="true" />
                    Verifying with AI
                  </span>
                )}
                {!aiLoading && viaAi && (
                  <span className="exp-desk-kpi-trace-ai-badge">AI verified</span>
                )}
              </div>
              {asPage && insightLines.length > 0 && (
                <ul className="exp-kpi-insight-list">
                  {insightLines.map((line) => <li key={line}>{line}</li>)}
                </ul>
              )}
              <p className="exp-desk-kpi-trace-confirmation">
                {confirmation || 'Checking this KPI with AI...'}
              </p>
              {aiError && <p className="exp-desk-kpi-trace-ai-error" role="alert">{aiError}</p>}
            </section>
          )}

          <section className="exp-desk-kpi-trace-section">
            <div className="exp-desk-kpi-trace-items-head">
              <h3 className="exp-desk-kpi-trace-section-title">
                Contributing records ({items.length})
              </h3>
            </div>

            {items.length === 0 ? (
              showEmptyAnimation ? (
                <div className="exp-desk-kpi-trace-empty-state">
                  {emptyAnimationReady ? (
                    <dotlottie-wc
                      src={window.__EXPENSES_EMPTY_LOTTIE_URL__}
                      autoplay
                      loop
                      aria-label="No contributing expense records"
                    />
                  ) : emptyAnimationError ? (
                    <p className="exp-desk-kpi-trace-empty" role="alert">
                      No records contributed to this KPI. The empty-state animation could not be loaded.
                    </p>
                  ) : (
                    <span className="exp-desk-kpi-trace-empty">Loading empty-state animation...</span>
                  )}
                </div>
              ) : (
                <p className="exp-desk-kpi-trace-empty">No records contributed to this KPI.</p>
              )
            ) : purchasesDesk ? (
              <div className="exp-desk-kpi-trace-table-wrap">
                <table className="exp-desk-kpi-trace-table exp-desk-kpi-trace-table--purchases">
                  <thead>
                    <tr>
                      <th>{refLabel}</th>
                      <th>Payee</th>
                    </tr>
                  </thead>
                  <tbody>
                    {items.map((item) => (
                      <tr key={`${item.id ?? 0}-${item.expenseNumber}-${item.date}`}>
                        <td>
                          <div className="exp-desk-kpi-trace-exp-cell">
                            <span className="exp-desk-kpi-trace-exp-no">{cellText(item.expenseNumber)}</span>
                            {item.date && (
                              <span className="exp-desk-kpi-trace-exp-date">{formatDate(item.date)}</span>
                            )}
                          </div>
                        </td>
                        <td className="exp-desk-kpi-trace-payee">{cellText(item.payee)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : asPage ? (
              <>
                <div className="exp-kpi-desk-wrap">
                  <table className="exp-kpi-desk-table">
                    <thead>
                      <tr>
                        <th style={{ width: '4%' }}>#</th>
                        <th style={{ width: '14%' }}>Expense No.</th>
                        <th style={{ width: '14%' }}>Payee</th>
                        <th style={{ width: '14%' }}>Prepared By</th>
                        <th style={{ width: '18%' }}>Description</th>
                        <th style={{ width: '12%' }} className="exp-kpi-desk-right">Amount / Date</th>
                        <th style={{ width: '9%' }}>Status</th>
                        <th style={{ width: '6%' }} className="exp-kpi-desk-center">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {pageItems.map((item, index) => (
                        <tr
                          key={`${item.id ?? 0}-${item.expenseNumber}-${item.date}`}
                          className={item.voucherUrl ? 'is-clickable' : undefined}
                          onClick={item.voucherUrl ? () => window.location.assign(item.voucherUrl) : undefined}
                        >
                          <td>{pageStart + index + 1}</td>
                          <td className="exp-kpi-desk-no">{cellText(item.expenseNumber)}</td>
                          <td>{cellText(item.payee)}</td>
                          <td>
                            <div>{cellText(item.preparedBy)}</div>
                            <div className="exp-kpi-desk-sub">{cellText(item.account)}</div>
                          </td>
                          <td><div className="exp-kpi-desk-desc">{cellText(item.description)}</div></td>
                          <td className="exp-kpi-desk-right"><div className="exp-kpi-desk-amt">{formatCurrency(item.amount, item.currency)}</div><div className="exp-kpi-desk-date">{formatDateTimeDigits(item.date, item.createdAt)}</div></td>
                          <td>
                            <span className={`exp-kpi-desk-status exp-kpi-desk-status--${String(item.status || '').toLowerCase()}`}>
                              {cellText(item.status)}
                            </span>
                          </td>
                          <td className="exp-kpi-desk-center">
                            {item.voucherUrl ? (
                              <a
                                className="exp-kpi-desk-open"
                                href={item.voucherUrl}
                                aria-label={`Open ${item.expenseNumber}`}
                                title="Open voucher"
                                onClick={(event) => event.stopPropagation()}
                              >
                                &#8943;
                              </a>
                            ) : null}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <div className="exp-kpi-mobile-cards" aria-label="Contributing expense records">
                  {pageItems.map((item, index) => {
                    const Card = item.voucherUrl ? 'a' : 'article';
                    return (
                      <Card
                        key={`mobile-${item.id ?? 0}-${item.expenseNumber}-${item.date}`}
                        className="exp-desk-mobile-card exp-kpi-mobile-card"
                        href={item.voucherUrl || undefined}
                      >
                        <div className="exp-desk-mobile-card-top">
                          <div className="exp-desk-mobile-card-main">
                            <span className="exp-kpi-mobile-number">
                              {pageStart + index + 1}. {cellText(item.expenseNumber)}
                            </span>
                            <span className="exp-kpi-mobile-payee">{cellText(item.payee)}</span>
                          </div>
                          <div className="exp-desk-mobile-card-aside">
                            <span className="exp-desk-mobile-card-amt">
                              {formatCurrency(item.amount, item.currency)}
                            </span>
                            <span className={`exp-kpi-desk-status exp-kpi-desk-status--${String(item.status || '').toLowerCase()}`}>
                              {cellText(item.status)}
                            </span>
                          </div>
                        </div>
                        {item.description ? (
                          <div className="exp-kpi-mobile-description">{cellText(item.description)}</div>
                        ) : null}
                        <div className="exp-desk-mobile-card-foot">
                          <div className="exp-desk-mobile-card-fact">
                            <span className="exp-desk-mobile-card-fact-body">
                              <span className="exp-desk-mobile-card-fact-label">Date</span>
                              <span className="exp-desk-mobile-card-fact-value">{formatDateTimeDigits(item.date, item.createdAt)}</span>
                            </span>
                          </div>
                          <div className="exp-desk-mobile-card-fact">
                            <span className="exp-desk-mobile-card-fact-body">
                              <span className="exp-desk-mobile-card-fact-label">Prepared by</span>
                              <span className="exp-desk-mobile-card-fact-value">{cellText(item.preparedBy)}</span>
                              {item.account ? (
                                <span className="exp-desk-mobile-card-fact-sub">{cellText(item.account)}</span>
                              ) : null}
                            </span>
                          </div>
                        </div>
                      </Card>
                    );
                  })}
                </div>

                <div className="exp-kpi-desk-footer">
                  <div className="exp-kpi-desk-showing">
                    Showing {items.length ? pageStart + 1 : 0} &ndash; {Math.min(pageStart + KPI_PAGE_SIZE, items.length)} of {items.length} records
                    <span className="exp-kpi-desk-totals">
                      {amountTotals.map(([currency, amount]) => (
                        <strong key={currency}>Total {formatCurrency(amount, currency)}</strong>
                      ))}
                    </span>
                  </div>
                  <div className="exp-kpi-desk-pager">
                    <button type="button" disabled={safePage <= 1} onClick={() => setPage(safePage - 1)} aria-label="Previous page">&lsaquo;</button>
                    {pagerPages(safePage, pageCount).map((p, i) =>
                      p === '...' ? (
                        <span key={`e${i}`} className="exp-kpi-desk-ellipsis">&hellip;</span>
                      ) : (
                        <button key={p} type="button" className={p === safePage ? 'is-active' : undefined} onClick={() => setPage(p)}>{p}</button>
                      ),
                    )}
                    <button type="button" disabled={safePage >= pageCount} onClick={() => setPage(safePage + 1)} aria-label="Next page">&rsaquo;</button>
                  </div>
                </div>
              </>
            ) : (
              <div className="exp-desk-kpi-trace-table-wrap">
                <table className="exp-desk-kpi-trace-table">
                  <thead>
                    <tr>
                      <th>Expense / Date</th>
                      <th>Payee</th>
                      <th>Account</th>
                      <th>Payment</th>
                      <th>Amount</th>
                    </tr>
                  </thead>
                  <tbody>
                    {items.map((item) => (
                      <tr key={`${item.id ?? 0}-${item.expenseNumber}-${item.date}`}>
                        <td>
                          <div className="exp-desk-kpi-trace-exp-cell">
                            <span className="exp-desk-kpi-trace-exp-no">{cellText(item.expenseNumber)}</span>
                            {item.date && (
                              <span className="exp-desk-kpi-trace-exp-date">{formatDate(item.date)}</span>
                            )}
                          </div>
                        </td>
                        <td className="exp-desk-kpi-trace-payee">{cellText(item.payee)}</td>
                        <td>{cellText(item.account)}</td>
                        <td>{cellText(item.payment)}</td>
                        <td className="exp-desk-kpi-trace-amt">{formatCurrency(item.amount, item.currency)}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="exp-desk-kpi-trace-total-row">
                      <td colSpan={4} className="exp-desk-kpi-trace-total-label">Total</td>
                      <td className="exp-desk-kpi-trace-amt exp-desk-kpi-trace-total-amt">
                        {amountTotals.map(([currency, amount]) => (
                          <div key={currency}>{formatCurrency(amount, currency)}</div>
                        ))}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            )}

            {trace.footnote && (
              <p className="exp-desk-kpi-trace-footnote">{trace.footnote}</p>
            )}
          </section>
        </div>
      </div>
  );

  if (asPage) {
    return (
      <div className="exp-desk-kpi-page">
        {dialog}
        <ScrollButtons />
      </div>
    );
  }

  return createPortal(
    <div className="exp-desk-modal-backdrop" onClick={onClose} role="presentation">
      {dialog}
    </div>,
    document.body,
  );
}
