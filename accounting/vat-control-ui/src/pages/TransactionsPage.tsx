import { useEffect, useState } from 'react';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { fetchTransactions } from '../api';
import type { TransactionsPayload, VatSource } from '../types';
import { buildCrumbs, categoryTitle, navigateVatView } from '../nav';
import Breadcrumbs from '../components/Breadcrumbs';

export default function TransactionsPage({ ym, source }: { ym: string; source: VatSource }) {
  const [data, setData] = useState<TransactionsPayload | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const payload = await fetchTransactions(ym, source);
        if (!cancelled) setData(payload);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load transactions.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [ym, source]);

  if (loading) return <div className="ld-boot">Loading transactions...</div>;
  if (error || !data) {
    return (
      <div className="ld-boot-error" role="alert">
        <strong>Could not load transactions</strong>
        <p>{error || 'Unknown error'}</p>
        <button type="button" className="ld-btn ld-btn--outline" onClick={() => navigateVatView({ name: 'month', ym })}>
          Back to month
        </button>
      </div>
    );
  }

  const crumbs = buildCrumbs(
    { name: 'transactions', ym, source },
    { monthLabel: data.label, sourceLabel: categoryTitle(source) },
  );

  const partyLabel = source === 'output' ? 'Customer' : source === 'purchases' ? 'Supplier' : 'Payee';
  const vatLabel = source === 'output' ? 'VAT Amount' : 'Input VAT';
  const showParty = source !== 'expenses';

  return (
    <div className="ld-page">
      <Breadcrumbs items={crumbs} />

      <div className="ld-header">
        <div>
          <button type="button" className="ld-back-link" onClick={() => navigateVatView({ name: 'month', ym })}>
            <ArrowLeft className="w-4 h-4" aria-hidden="true" /> Back
          </button>
          <h1>
            {categoryTitle(source)} / {data.label}
          </h1>
          <p className="ld-header-sub">
            {data.transactionCount} transaction{data.transactionCount === 1 ? '' : 's'}
          </p>
        </div>
        <div className="ld-header-hero">
          <div className="ld-header-hero-label">Total VAT</div>
          <div className="ld-header-hero-value">{data.totalVatDisplay}</div>
        </div>
      </div>

      <section className="ld-card">
        <div className="ld-card-h">
          <h3>{categoryTitle(source)} VAT transactions</h3>
        </div>
        <div className="ld-card-b" style={{ padding: 0, overflowX: 'auto' }}>
          {data.transactions.length === 0 ? (
            <div className="ld-empty" style={{ padding: '1.5rem' }}>
              No VAT activity for this period.
            </div>
          ) : (
            <table className="ld-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Reference</th>
                  {showParty && <th>{partyLabel}</th>}
                  <th>Description</th>
                  <th>Taxable Amount</th>
                  <th>VAT Rate</th>
                  <th>{vatLabel}</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {data.transactions.map((tx) => (
                  <tr key={`${source}-${tx.id}`}>
                    <td>{tx.date ? String(tx.date).slice(0, 10) : '-'}</td>
                    <td>
                      {tx.sourceUrl ? (
                        <a href={tx.sourceUrl} className="ld-link-ext" target="_blank" rel="noopener noreferrer">
                          {tx.reference || '-'}
                          <ExternalLink className="w-3.5 h-3.5 inline" aria-hidden="true" style={{ marginLeft: 4 }} />
                        </a>
                      ) : (
                        tx.reference || '-'
                      )}
                    </td>
                    {showParty && <td>{tx.party || '-'}</td>}
                    <td>{tx.description || '-'}</td>
                    <td>{tx.taxableAmountDisplay || '-'}</td>
                    <td>{tx.vatRateDisplay || '-'}</td>
                    <td>{tx.vatAmountDisplay}</td>
                    <td>{tx.status || '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </section>
    </div>
  );
}
