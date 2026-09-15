import { useEffect, useMemo, useState } from 'react';
import { ArrowLeft, ChevronRight, Landmark, Banknote, Smartphone, Wallet } from 'lucide-react';
import { fetchLiquidityAccounts } from '../api';
import type { LiquidityAccountsPayload, LiquidityBucket } from '../types';
import { bucketTitle, buildCrumbs, navigateLdView } from '../nav';
import Breadcrumbs from '../components/Breadcrumbs';

const bucketIcon = {
  bank: Landmark,
  cash: Banknote,
  mobile: Smartphone,
  other: Wallet,
} as const;

export default function LiquidityAccountsPage({ bucket = null }: { bucket?: LiquidityBucket | null }) {
  const [data, setData] = useState<LiquidityAccountsPayload | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const payload = await fetchLiquidityAccounts();
        if (!cancelled) setData(payload);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load liquidity accounts.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const filteredGroups = useMemo(() => {
    if (!data) return [];
    if (!bucket) return data.groups;
    return data.groups.filter((group) => group.key === bucket);
  }, [data, bucket]);

  const sectionTotal = useMemo(() => {
    if (!data) return null;
    if (!bucket) {
      return {
        label: 'Total Liquidity',
        value: data.totalLiquidity,
        display: data.totalLiquidityDisplay,
        count: data.accountCount,
      };
    }
    const group = data.groups.find((g) => g.key === bucket);
    return {
      label: bucketTitle(bucket),
      value: group?.total ?? 0,
      display: group?.totalDisplay ?? 'TZS 0.00',
      count: group?.accountCount ?? 0,
    };
  }, [data, bucket]);

  if (loading) {
    return <div className="ld-boot">Loading {bucketTitle(bucket).toLowerCase()} details...</div>;
  }

  if (error || !data || !sectionTotal) {
    return (
      <div className="ld-boot-error" role="alert">
        <strong>Could not load liquidity details</strong>
        <p>{error || 'Unknown error'}</p>
        <button type="button" className="ld-btn ld-btn--outline" onClick={() => navigateLdView({ name: 'dashboard' })}>
          Back to dashboard
        </button>
      </div>
    );
  }

  const crumbs = buildCrumbs({ name: 'liquidity', bucket });

  return (
    <div className="ld-page">
      <Breadcrumbs items={crumbs} />

      <div className="ld-header">
        <div>
          <button
            type="button"
            className="ld-back-link"
            onClick={() => navigateLdView({ name: 'dashboard' })}
          >
            <ArrowLeft className="w-4 h-4" aria-hidden="true" /> Back
          </button>
          <h1>{sectionTotal.label}</h1>
          <p className="ld-header-sub">
            {sectionTotal.count} active account{sectionTotal.count === 1 ? '' : 's'}
            {bucket ? ' in this category' : ' across all liquidity buckets'}
          </p>
        </div>
        <div className="ld-header-hero">
          <div className="ld-header-hero-label">{sectionTotal.label}</div>
          <div className="ld-header-hero-value">{sectionTotal.display}</div>
        </div>
      </div>

      <p className="ld-note">
        Balances use the same live formula as the dashboard: opening balance + credits - debits.
        Click an account to review monthly movements.
      </p>

      {filteredGroups.length === 0 ? (
        <div className="ld-card">
          <div className="ld-card-b ld-empty">No active accounts found in this category.</div>
        </div>
      ) : (
        filteredGroups.map((group) => {
          const Icon = bucketIcon[group.key as keyof typeof bucketIcon] || Wallet;
          return (
            <section key={group.key} className="ld-card ld-drill-section">
              <div className="ld-card-h ld-drill-section-h">
                <div className="ld-drill-section-title">
                  <span className={`ld-drill-bucket-icon ld-drill-bucket-icon--${group.key}`}>
                    <Icon className="w-4 h-4" aria-hidden="true" />
                  </span>
                  <h3>{group.label}</h3>
                </div>
                <div className="ld-drill-section-total">{group.totalDisplay}</div>
              </div>
              <div className="ld-card-b ld-account-list">
                {group.accounts.map((account) => (
                  <button
                    key={account.id}
                    type="button"
                    className="ld-account-row"
                    onClick={() => navigateLdView({ name: 'account', accountId: account.id })}
                  >
                    <div className="ld-account-row-main">
                      <div className="ld-account-row-name">{account.name}</div>
                      <div className="ld-account-row-meta">
                        {account.code ? `${account.code} - ` : ''}
                        {account.typeLabel}
                        {account.parentId > 0 ? ' - Sub-account' : ''}
                      </div>
                    </div>
                    <div className="ld-account-row-side">
                      <div className={`ld-account-row-bal${account.balance < 0 ? ' is-neg' : ''}`}>
                        {account.balanceDisplay}
                      </div>
                      <ChevronRight className="w-4 h-4 ld-account-row-chevron" aria-hidden="true" />
                    </div>
                  </button>
                ))}
              </div>
            </section>
          );
        })
      )}
    </div>
  );
}
