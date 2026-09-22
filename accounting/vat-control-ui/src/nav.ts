import type { VatCategory, VatSource, VatView } from './types';

const CATEGORIES: VatCategory[] = ['output', 'purchases', 'expenses'];

export function parseCategory(raw: string | null | undefined): VatCategory | null {
  const value = String(raw || '').toLowerCase();
  return (CATEGORIES as string[]).includes(value) ? (value as VatCategory) : null;
}

export function categoryTitle(category: VatCategory | null | undefined): string {
  switch (category) {
    case 'output':
      return 'Revenue';
    case 'purchases':
      return 'Purchases';
    case 'expenses':
      return 'Expenses';
    default:
      return 'VAT';
  }
}

export function readVatView(): VatView {
  const params = new URLSearchParams(window.location.search);
  const view = (params.get('vat_view') || params.get('view') || 'dashboard').toLowerCase();
  const category = parseCategory(params.get('category'));
  const year = Number(params.get('year') || 0);
  const ym = String(params.get('ym') || '');
  const source = parseCategory(params.get('source')) as VatSource | null;

  // Primary drill-down: Year -> Month -> Category transactions
  if (view === 'year' && year >= 2000) {
    return { name: 'year', year };
  }
  if (view === 'month' && /^\d{4}-\d{2}$/.test(ym)) {
    return { name: 'month', ym };
  }
  if (view === 'transactions' && /^\d{4}-\d{2}$/.test(ym) && source) {
    return { name: 'transactions', ym, source };
  }

  // Legacy: category-first URLs
  if (view === 'category' && category) {
    return { name: 'category', category };
  }

  return { name: 'dashboard' };
}

export function navigateVatView(view: VatView, replace = false): void {
  const url = new URL(window.location.href);
  const keep = new URLSearchParams();
  const module = url.searchParams.get('module');
  const companySlug = url.searchParams.get('company_slug');
  if (module) keep.set('module', module);
  if (companySlug) keep.set('company_slug', companySlug);

  if (view.name === 'dashboard') {
    // no vat_view
  } else if (view.name === 'category') {
    keep.set('vat_view', 'category');
    keep.set('category', view.category);
  } else if (view.name === 'year') {
    keep.set('vat_view', 'year');
    keep.set('year', String(view.year));
  } else if (view.name === 'month') {
    keep.set('vat_view', 'month');
    keep.set('ym', view.ym);
  } else if (view.name === 'transactions') {
    keep.set('vat_view', 'transactions');
    keep.set('ym', view.ym);
    keep.set('source', view.source);
  }

  url.search = keep.toString();
  if (replace) {
    window.history.replaceState({}, '', url.toString());
  } else {
    window.history.pushState({}, '', url.toString());
  }
  window.dispatchEvent(new PopStateEvent('popstate'));
}

export type Crumb = { label: string; onClick?: () => void };

export function buildCrumbs(
  view: VatView,
  labels: { yearLabel?: string; monthLabel?: string; sourceLabel?: string } = {},
): Crumb[] {
  const crumbs: Crumb[] = [
    { label: 'VAT Control', onClick: () => navigateVatView({ name: 'dashboard' }) },
  ];

  if (view.name === 'dashboard') return crumbs;

  if (view.name === 'category') {
    crumbs.push({ label: categoryTitle(view.category) });
    return crumbs;
  }

  if (view.name === 'year') {
    crumbs.push({ label: labels.yearLabel || String(view.year) });
    return crumbs;
  }

  const year =
    view.name === 'month' || view.name === 'transactions' ? Number(view.ym.slice(0, 4)) : 0;

  if (view.name === 'month') {
    crumbs.push({
      label: String(year),
      onClick: () => navigateVatView({ name: 'year', year }),
    });
    crumbs.push({ label: labels.monthLabel || view.ym });
    return crumbs;
  }

  // transactions: VAT Control -> Year -> Month -> Category
  crumbs.push({
    label: String(year),
    onClick: () => navigateVatView({ name: 'year', year }),
  });
  crumbs.push({
    label: labels.monthLabel || view.ym,
    onClick: () => navigateVatView({ name: 'month', ym: view.ym }),
  });
  crumbs.push({
    label: labels.sourceLabel || categoryTitle(view.source),
  });
  return crumbs;
}
