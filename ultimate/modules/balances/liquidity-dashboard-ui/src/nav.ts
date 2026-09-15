import type { LdView, LiquidityBucket } from '../types';

const BUCKETS: LiquidityBucket[] = ['cash', 'bank', 'mobile', 'other'];

export function parseBucket(raw: string | null | undefined): LiquidityBucket | null {
  const value = String(raw || '').toLowerCase();
  return (BUCKETS as string[]).includes(value) ? (value as LiquidityBucket) : null;
}

export function bucketTitle(bucket: LiquidityBucket | null | undefined): string {
  switch (bucket) {
    case 'cash':
      return 'Cash on Hand';
    case 'bank':
      return 'Bank Accounts';
    case 'mobile':
      return 'Mobile Money';
    case 'other':
      return 'Other Accounts';
    default:
      return 'Total Liquidity';
  }
}

export function readLdView(): LdView {
  const params = new URLSearchParams(window.location.search);
  const view = (params.get('ld_view') || params.get('view') || 'dashboard').toLowerCase();
  const accountId = Number(params.get('account_id') || 0);
  const ym = String(params.get('ym') || '');
  const txId = Number(params.get('tx_id') || 0);
  const bucket = parseBucket(params.get('bucket'));

  if (view === 'liquidity' || view === 'details') {
    return { name: 'liquidity', bucket };
  }
  if (view === 'account' && accountId > 0) {
    return { name: 'account', accountId };
  }
  if (view === 'month' && accountId > 0 && /^\d{4}-\d{2}$/.test(ym)) {
    return { name: 'month', accountId, ym };
  }
  if (view === 'transaction' && accountId > 0 && txId > 0) {
    return {
      name: 'transaction',
      accountId,
      ym: /^\d{4}-\d{2}$/.test(ym) ? ym : '',
      txId,
    };
  }
  return { name: 'dashboard' };
}

export function navigateLdView(view: LdView, replace = false): void {
  const url = new URL(window.location.href);
  const keep = new URLSearchParams();
  const module = url.searchParams.get('module');
  const companySlug = url.searchParams.get('company_slug');
  if (module) keep.set('module', module);
  if (companySlug) keep.set('company_slug', companySlug);

  if (view.name === 'dashboard') {
    // no ld_view
  } else if (view.name === 'liquidity') {
    keep.set('ld_view', 'liquidity');
    if (view.bucket) keep.set('bucket', view.bucket);
  } else if (view.name === 'account') {
    keep.set('ld_view', 'account');
    keep.set('account_id', String(view.accountId));
  } else if (view.name === 'month') {
    keep.set('ld_view', 'month');
    keep.set('account_id', String(view.accountId));
    keep.set('ym', view.ym);
  } else if (view.name === 'transaction') {
    keep.set('ld_view', 'transaction');
    keep.set('account_id', String(view.accountId));
    if (view.ym) keep.set('ym', view.ym);
    keep.set('tx_id', String(view.txId));
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

export function buildCrumbs(view: LdView, labels: {
  accountName?: string;
  monthLabel?: string;
  txLabel?: string;
} = {}): Crumb[] {
  const crumbs: Crumb[] = [
    { label: 'Liquidity Dashboard', onClick: () => navigateLdView({ name: 'dashboard' }) },
  ];

  if (view.name === 'dashboard') return crumbs;

  const liquidityBucket = view.name === 'liquidity' ? view.bucket ?? null : null;
  crumbs.push({
    label: bucketTitle(liquidityBucket),
    onClick:
      view.name === 'liquidity'
        ? undefined
        : () => navigateLdView({ name: 'liquidity' }),
  });

  if (view.name === 'liquidity') return crumbs;

  const accountId = 'accountId' in view ? view.accountId : 0;
  crumbs.push({
    label: labels.accountName || `Account #${accountId}`,
    onClick:
      view.name === 'account'
        ? undefined
        : () => navigateLdView({ name: 'account', accountId }),
  });

  if (view.name === 'account') return crumbs;

  const ym = 'ym' in view ? view.ym : '';
  crumbs.push({
    label: labels.monthLabel || ym || 'Month',
    onClick:
      view.name === 'month' || !ym
        ? undefined
        : () => navigateLdView({ name: 'month', accountId, ym }),
  });

  if (view.name === 'month') return crumbs;

  crumbs.push({
    label: labels.txLabel || `Transaction #${view.txId}`,
  });

  return crumbs;
}
