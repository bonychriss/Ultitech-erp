import { useEffect, useState } from 'react';
import {
  Percent,
  TrendingUp,
  ShoppingCart,
  Receipt,
  Scale,
  ChevronDown,
  CircleHelp,
  List,
  X,
} from 'lucide-react';
import { fetchInit } from '../api';
import type { DashboardInit, VatCategory } from '../types';
import { navigateVatView } from '../nav';

const categoryIcon = {
  output: TrendingUp,
  purchases: ShoppingCart,
  expenses: Receipt,
} as const;

const categoryTone: Record<string, string> = {
  output: 'bg-blue-50 text-blue-600',
  purchases: 'bg-indigo-50 text-indigo-600',
  expenses: 'bg-violet-50 text-violet-600',
};

function statusClass(status: string): string {
  if (status === 'closed') return 'is-closed';
  if (status === 'reconciled') return 'is-reconciled';
  return 'is-open';
}

function positionClass(position: string): string {
  if (position === 'payable') return 'is-payable';
  if (position === 'credit') return 'is-credit';
  return 'is-nil';
}

function positionLabel(position: string): string {
  if (position === 'payable') return 'Payable';
  if (position === 'credit') return 'Credit';
  return 'Nil';
}

export default function DashboardPage() {
  const [data, setData] = useState<DashboardInit | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [manualOpen, setManualOpen] = useState(false);

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

  if (loading) {
    return (
      <div className="ld-loading" role="status" aria-live="polite">
        <span className="ld-loading-spinner" aria-hidden="true" />
        Loading VAT Control...
      </div>
    );
  }

  if (error || !data) {
    return <div className="ld-error">{error || 'Dashboard unavailable.'}</div>;
  }

  const { kpis, categories, history } = data;
  const years = Array.from(
    new Set(history.map((row) => Number(row.ym.slice(0, 4))).filter((y) => y >= 2000)),
  ).sort((a, b) => b - a);
  if (!years.includes(data.year)) {
    years.unshift(data.year);
  }

  return (
    <div className="ld-dash">
      <div className="ld-header ld-header--actions-only">
        <div className="ld-header-actions">
          <button type="button" className="ld-btn ld-btn--outline" onClick={() => setManualOpen(true)}>
            <CircleHelp className="w-4 h-4" aria-hidden="true" /> Manual
          </button>
        </div>
      </div>

      <div className="ld-kpi-grid">
        <button
          type="button"
          className="ld-kpi-card ld-kpi-card--clickable"
          onClick={() => navigateVatView({ name: 'month', ym: data.thisYm })}
          title="Open this month detail"
        >
          <div className="ld-kpi-text">
            <div className="ld-kpi-label">This Month Net VAT</div>
            <div className={`ld-kpi-value${kpis.thisMonthNet < 0 ? ' is-neg' : ''}`}>
              {kpis.thisMonthNetDisplay}
            </div>
            <div className="ld-kpi-sub">{data.thisMonthLabel}</div>
            <div className="ld-kpi-sub">
              <span className={`ld-pos ${positionClass(kpis.thisMonthPosition)}`}>
                {positionLabel(kpis.thisMonthPosition)}
              </span>{' '}
              <span className={`ld-status ${statusClass(kpis.thisMonthStatus)}`}>{kpis.thisMonthStatus}</span>
            </div>
          </div>
          <div className="ld-kpi-icon bg-amber-50 text-amber-600">
            <Scale className="w-5 h-5" />
          </div>
        </button>
        <button
          type="button"
          className="ld-kpi-card ld-kpi-card--clickable"
          onClick={() => navigateVatView({ name: 'year', year: data.year })}
          title="Open year VAT periods"
        >
          <div className="ld-kpi-text">
            <div className="ld-kpi-label">YTD Output VAT</div>
            <div className="ld-kpi-value">{kpis.ytdOutputDisplay}</div>
            <div className="ld-kpi-sub">{data.year} year to date</div>
          </div>
          <div className="ld-kpi-icon bg-blue-50 text-blue-600">
            <TrendingUp className="w-5 h-5" />
          </div>
        </button>
        <button
          type="button"
          className="ld-kpi-card ld-kpi-card--clickable"
          onClick={() => navigateVatView({ name: 'year', year: data.year })}
          title="Open year VAT periods"
        >
          <div className="ld-kpi-text">
            <div className="ld-kpi-label">YTD Input VAT</div>
            <div className="ld-kpi-value">{kpis.ytdInputDisplay}</div>
            <div className="ld-kpi-sub">Purchases + expenses</div>
          </div>
          <div className="ld-kpi-icon bg-indigo-50 text-indigo-600">
            <ShoppingCart className="w-5 h-5" />
          </div>
        </button>
        <button
          type="button"
          className="ld-kpi-card ld-kpi-card--clickable"
          onClick={() => navigateVatView({ name: 'year', year: data.year })}
          title="Open year VAT periods"
        >
          <div className="ld-kpi-text">
            <div className="ld-kpi-label">YTD Net VAT</div>
            <div className={`ld-kpi-value${kpis.ytdNet < 0 ? ' is-neg' : ''}`}>{kpis.ytdNetDisplay}</div>
            <div className="ld-kpi-sub">Opening {kpis.openingBalanceDisplay}</div>
            <div className="ld-kpi-sub">Closing {kpis.closingBalanceDisplay}</div>
          </div>
          <div className="ld-kpi-icon bg-green-50 text-green-600">
            <Percent className="w-5 h-5" />
          </div>
        </button>
      </div>

      <div className="ld-grid">
        {categories.map((cat) => {
          const Icon = categoryIcon[cat.key as VatCategory] || Percent;
          return (
            <button
              key={cat.key}
              type="button"
              className="ld-kpi-card ld-kpi-card--clickable"
              onClick={() => navigateVatView({ name: 'year', year: data.year })}
            >
              <div className="ld-kpi-text">
                <div className="ld-kpi-label">{cat.label}</div>
                <div className="ld-kpi-value">{cat.totalDisplay}</div>
                <div className="ld-kpi-sub">YTD {data.year}</div>
                <div className="ld-kpi-sub">This month {cat.thisMonthDisplay}</div>
              </div>
              <div className={`ld-kpi-icon ${categoryTone[cat.key] || ''}`}>
                <Icon className="w-5 h-5" />
              </div>
            </button>
          );
        })}
      </div>

      <section className="ld-card" style={{ marginTop: '1.25rem' }}>
        <div className="ld-card-h">
          <h3>VAT Years</h3>
          <span className="ld-kpi-sub">{years.length} year{years.length === 1 ? '' : 's'}</span>
        </div>
        <div className="ld-card-b">
          <div className="ld-year-chips" role="group" aria-label="Filter by year">
            {years.map((year) => (
              <button
                key={year}
                type="button"
                className="ld-year-chip"
                onClick={() => navigateVatView({ name: 'year', year })}
                title={`Open ${year} VAT periods`}
              >
                <span>{year}</span>
                <span className="ld-year-chip-meta">View periods</span>
                <ChevronDown className="w-4 h-4 ld-year-chip-chevron" aria-hidden="true" />
              </button>
            ))}
          </div>
        </div>
      </section>

      <section className="ld-card" style={{ marginTop: '1.25rem' }}>
        <div className="ld-card-h">
          <h3>
            <List className="w-4 h-4" aria-hidden="true" />
            VAT Period History
          </h3>
          <span className="ld-kpi-sub">
            {history.length} record{history.length === 1 ? '' : 's'}
          </span>
        </div>
        <div className="ld-card-b" style={{ paddingTop: 0 }}>
          {history.length === 0 ? (
            <div className="ld-empty" style={{ padding: '1.5rem' }}>
              No VAT activity months found yet.
            </div>
          ) : (
            <div className="ld-table-wrap ld-table-wrap--desk">
              <table className="ld-table ld-table--desk">
                <thead>
                  <tr>
                    <th className="ld-sn">S/N</th>
                    <th>Period</th>
                    <th className="is-num">Output</th>
                    <th className="is-num">Purchases</th>
                    <th className="is-num">Expenses</th>
                    <th className="is-num">Net</th>
                    <th>Position</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {history.map((row, idx) => (
                    <tr
                      key={row.ym}
                      className="ld-row"
                      onClick={() => navigateVatView({ name: 'month', ym: row.ym })}
                    >
                      <td className="ld-sn">{idx + 1}</td>
                      <td>
                        <div className="ld-cell-main">{row.label}</div>
                        <div className="ld-cell-sub">{row.ym}</div>
                      </td>
                      <td className="is-num">{row.outputDisplay}</td>
                      <td className="is-num">{row.inputPurchasesDisplay}</td>
                      <td className="is-num">{row.inputExpensesDisplay}</td>
                      <td className={`is-num${row.net < 0 ? ' is-neg' : ''}`}>{row.netDisplay}</td>
                      <td className="ld-badge-cell">
                        <span className={`ld-vbadge ${positionClass(row.position)}`}>
                          {positionLabel(row.position)}
                        </span>
                      </td>
                      <td className="ld-badge-cell">
                        <span className={`ld-vbadge ${statusClass(row.status)}`}>{row.statusLabel}</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </section>

      {manualOpen && (
        <div className="ld-modal-backdrop" role="presentation" onClick={() => setManualOpen(false)}>
          <div
            className="ld-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="vat-manual-title"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="ld-modal-h">
              <h2 id="vat-manual-title">VAT Control user manual</h2>
              <button type="button" className="ld-modal-x" onClick={() => setManualOpen(false)} aria-label="Close">
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="ld-modal-b">
              <p>Hierarchical VAT monitoring using the same drill-down pattern as Liquidity Dashboard.</p>
              <h3>1. Dashboard</h3>
              <p>KPIs show this month and YTD net / output / input. Click any month or year to drill in.</p>
              <h3>2. Drill-down</h3>
              <ul>
                <li>
                  <strong>VAT Control to Year to Month to Revenue / Purchases / Expenses to Transactions</strong> for
                  Output, Purchases, and Expenses.
                </li>
                <li>Back from a month returns to VAT Control. Use the year crumb to open that year’s periods.</li>
              </ul>
              <h3>3. Closing</h3>
              <p>
                Reconcile compares system totals (currently same source - difference 0), then Close locks the period
                and carries opening balance forward.
              </p>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
