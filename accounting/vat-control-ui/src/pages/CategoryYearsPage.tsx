import { useEffect, useState } from 'react';
import { ArrowLeft, ChevronRight } from 'lucide-react';
import { fetchCategoryYears } from '../api';
import type { CategoryYearsPayload, VatCategory } from '../types';
import { buildCrumbs, categoryTitle, navigateVatView } from '../nav';
import Breadcrumbs from '../components/Breadcrumbs';

export default function CategoryYearsPage({ category }: { category: VatCategory }) {
  const [data, setData] = useState<CategoryYearsPayload | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const payload = await fetchCategoryYears(category);
        if (!cancelled) setData(payload);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load years.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [category]);

  if (loading) return <div className="ld-boot">Loading {categoryTitle(category)} years...</div>;
  if (error || !data) {
    return (
      <div className="ld-boot-error" role="alert">
        <strong>Could not load category</strong>
        <p>{error || 'Unknown error'}</p>
        <button type="button" className="ld-btn ld-btn--outline" onClick={() => navigateVatView({ name: 'dashboard' })}>
          Back to dashboard
        </button>
      </div>
    );
  }

  const crumbs = buildCrumbs({ name: 'category', category });

  return (
    <div className="ld-page">
      <Breadcrumbs items={crumbs} />

      <div className="ld-header">
        <div>
          <button type="button" className="ld-back-link" onClick={() => navigateVatView({ name: 'dashboard' })}>
            <ArrowLeft className="w-4 h-4" aria-hidden="true" /> Back
          </button>
          <h1>{data.categoryLabel}</h1>
          <p className="ld-header-sub">
            {data.yearCount} year{data.yearCount === 1 ? '' : 's'} with activity
          </p>
        </div>
        <div className="ld-header-hero">
          <div className="ld-header-hero-label">All-time total</div>
          <div className="ld-header-hero-value">{data.grandTotalDisplay}</div>
        </div>
      </div>

      <section className="ld-card">
        <div className="ld-card-h">
          <h3>Years</h3>
        </div>
        <div className="ld-card-b ld-month-list">
          {data.years.length === 0 ? (
            <div className="ld-empty">No years found for this category.</div>
          ) : (
            data.years.map((year) => (
              <button
                key={year.year}
                type="button"
                className="ld-month-row"
                onClick={() => navigateVatView({ name: 'year', year: year.year })}
              >
                <div className="ld-month-row-main">
                  <div className="ld-month-row-name">{year.label}</div>
                  <div className="ld-month-row-meta">
                    {year.monthCount} month{year.monthCount === 1 ? '' : 's'}
                  </div>
                </div>
                <div className="ld-month-row-side">
                  <div className="ld-month-row-close">{year.totalDisplay}</div>
                  <div className="ld-month-row-close-label">Total</div>
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
