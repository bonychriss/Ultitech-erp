import { useEffect, useState } from 'react';
import { ArrowLeft, ChevronRight } from 'lucide-react';
import { fetchYearMonths } from '../api';
import type { YearMonthsPayload } from '../types';
import { buildCrumbs, navigateVatView } from '../nav';
import Breadcrumbs from '../components/Breadcrumbs';

function positionLabel(position: string): string {
  if (position === 'payable') return 'VAT Payable';
  if (position === 'credit') return 'VAT Credit';
  return 'Nil';
}

export default function YearMonthsPage({ year }: { year: number }) {
  const [data, setData] = useState<YearMonthsPayload | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const payload = await fetchYearMonths(year);
        if (!cancelled) setData(payload);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load months.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [year]);

  if (loading) return <div className="ld-boot">Loading {year} months...</div>;
  if (error || !data) {
    return (
      <div className="ld-boot-error" role="alert">
        <strong>Could not load year</strong>
        <p>{error || 'Unknown error'}</p>
        <button type="button" className="ld-btn ld-btn--outline" onClick={() => navigateVatView({ name: 'dashboard' })}>
          Back to dashboard
        </button>
      </div>
    );
  }

  const crumbs = buildCrumbs({ name: 'year', year }, { yearLabel: String(year) });

  return (
    <div className="ld-page">
      <Breadcrumbs items={crumbs} />

      <div className="ld-header">
        <div>
          <button type="button" className="ld-back-link" onClick={() => navigateVatView({ name: 'dashboard' })}>
            <ArrowLeft className="w-4 h-4" aria-hidden="true" /> Back
          </button>
          <h1>{year}</h1>
          <p className="ld-header-sub">
            {data.monthCount} VAT period{data.monthCount === 1 ? '' : 's'}
          </p>
        </div>
        <div className="ld-header-hero">
          <div className="ld-header-hero-label">Year net VAT</div>
          <div className="ld-header-hero-value">{data.yearNetDisplay || data.yearTotalDisplay}</div>
        </div>
      </div>

      <div className="ld-summary-grid" style={{ marginBottom: '1.25rem' }}>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Output VAT</div>
          <div className="ld-summary-value" style={{ fontSize: '1.15rem' }}>{data.yearOutputDisplay || '-'}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Input VAT</div>
          <div className="ld-summary-value" style={{ fontSize: '1.15rem' }}>{data.yearInputDisplay || '-'}</div>
        </div>
        <div className="ld-summary-card">
          <div className="ld-summary-label">Net VAT</div>
          <div className="ld-summary-value" style={{ fontSize: '1.15rem' }}>{data.yearNetDisplay || data.yearTotalDisplay}</div>
        </div>
      </div>

      <section className="ld-card">
        <div className="ld-card-h">
          <h3>VAT Periods</h3>
        </div>
        <div className="ld-card-b ld-month-list">
          {data.months.length === 0 ? (
            <div className="ld-empty">No VAT periods found for {year}.</div>
          ) : (
            data.months.map((month) => (
              <button
                key={month.ym}
                type="button"
                className="ld-month-row"
                onClick={() => navigateVatView({ name: 'month', ym: month.ym })}
              >
                <div className="ld-month-row-main">
                  <div className="ld-month-row-name">{month.label}</div>
                  <div className="ld-month-row-meta">
                    {month.statusLabel} / {positionLabel(month.position)}
                  </div>
                  <div className="ld-month-row-flow">
                    <span>Output {month.outputDisplay || '-'}</span>
                    <span>Input {month.inputTotalDisplay || '-'}</span>
                    <span className={month.net < 0 ? 'is-out' : 'is-in'}>Net {month.netDisplay}</span>
                  </div>
                </div>
                <div className="ld-month-row-side">
                  <div className={`ld-month-row-close${month.net < 0 ? ' is-neg' : ''}`}>{month.netDisplay}</div>
                  <div className="ld-month-row-close-label">Net</div>
                  <ChevronRight className="w-4 h-4" aria-hidden="true" />
                </div>
              </button>
            ))
          )}
        </div>
      </section>
    </div>
  );
}
