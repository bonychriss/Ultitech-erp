import { useEffect, useMemo, useState } from 'react';
import { Inbox, Loader2, Mail, Phone, Search, User, X } from 'lucide-react';
import { fetchQuoteRequestsInit } from '../api/quoteRequestsDesk';

function formatWhen(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return raw;
  return d.toLocaleString();
}

export default function QuoteRequestsPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError('');
      try {
        const data = await fetchQuoteRequestsInit();
        if (!cancelled) setInit(data);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load quote requests.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const requests = useMemo(() => init?.requests || [], [init]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return requests;
    return requests.filter((row) => {
      const hay = [
        row.quote_number,
        row.customer_name,
        row.customer_email,
        row.customer_phone,
        row.notes,
        ...(row.items || []).map((item) => `${item.product_name} ${item.product_sku}`),
      ]
        .join(' ')
        .toLowerCase();
      return hay.includes(q);
    });
  }, [requests, search]);

  if (loading && !init) {
    return (
      <div className="exp-desk-page exp-desk-boot-loading" role="status" aria-live="polite">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading quote requests...</span>
      </div>
    );
  }

  if (error || !init) {
    return (
      <div className="exp-desk-page">
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">
          {error || 'Could not load quote requests.'}
        </div>
      </div>
    );
  }

  return (
    <div className="exp-desk-page">
      <div className="exp-desk-page-header">
        <div className="exp-desk-page-header-search exp-desk-page-header-search--desktop">
          <div className="exp-desk-search-field">
            <Search className="exp-desk-search-icon" aria-hidden="true" />
            <input
              id="qr-desk-search"
              type="search"
              className="exp-desk-search-input"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search number, customer, product..."
              autoComplete="off"
              aria-label="Search quote requests"
            />
            {search.trim() !== '' && (
              <button
                type="button"
                className="exp-desk-search-clear"
                onClick={() => setSearch('')}
                aria-label="Clear search"
              >
                <X className="w-4 h-4" />
              </button>
            )}
          </div>
        </div>

        <div className="exp-desk-page-header-actions">
          <div className="exp-desk-toolbar-secondary">
            {init.urls?.quotations ? (
              <a href={init.urls.quotations} className="exp-desk-btn exp-desk-btn-ghost">
                Quotations
              </a>
            ) : null}
          </div>
        </div>
      </div>

      <section className="exp-desk-kpi-grid qt-kpi-grid" aria-label="Quote request summary">
        <div className="exp-desk-kpi exp-desk-kpi-card">
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--violet">
            <Inbox size={20} aria-hidden="true" />
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">website requests</div>
            <div className="exp-desk-kpi-value">{requests.length}</div>
          </div>
        </div>
        <div className="exp-desk-kpi exp-desk-kpi-card">
          <div className="exp-desk-kpi-icon exp-desk-kpi-icon--teal">
            <Search size={20} aria-hidden="true" />
          </div>
          <div className="exp-desk-kpi-body">
            <div className="exp-desk-kpi-label">listed now</div>
            <div className="exp-desk-kpi-value">{filtered.length}</div>
            <div className="exp-desk-kpi-helper">matching current search</div>
          </div>
        </div>
      </section>

      <section className="exp-desk-results">
        <div className="exp-desk-results-meta">
          {filtered.length} {filtered.length === 1 ? 'result' : 'results'}
        </div>

        {filtered.length === 0 ? (
          <div className="exp-desk-empty">
            <p className="exp-desk-empty-title">No quote requests found</p>
            <p>Website quotation requests from Roadmaster Spares will appear here.</p>
          </div>
        ) : (
          <div className="qr-desk-list">
            {filtered.map((row) => (
              <article key={row.quote_number} className="qr-desk-card">
                <header className="qr-desk-card-head">
                  <div>
                    <strong>{row.quote_number}</strong>
                    <span className="qr-desk-when">{formatWhen(row.created_at)}</span>
                  </div>
                  <span className="qt-status qt-status--cyan">{row.status || 'new'}</span>
                </header>

                <div className="qr-desk-customer">
                  <span>
                    <User size={14} aria-hidden="true" /> {row.customer_name || 'Customer'}
                  </span>
                  {row.customer_email ? (
                    <span>
                      <Mail size={14} aria-hidden="true" /> {row.customer_email}
                    </span>
                  ) : null}
                  {row.customer_phone ? (
                    <span>
                      <Phone size={14} aria-hidden="true" /> {row.customer_phone}
                    </span>
                  ) : null}
                </div>

                <ul className="qr-desk-items">
                  {(row.items || []).map((item) => (
                    <li key={`${row.quote_number}-${item.id}`}>
                      <div>
                        <strong>{item.product_name || 'Product'}</strong>
                        <em>SKU {item.product_sku || '-'}</em>
                      </div>
                      <span>Qty {item.quantity}</span>
                    </li>
                  ))}
                </ul>

                {row.notes ? (
                  <div className="qr-desk-notes">
                    <strong>Additional requirements</strong>
                    <p>{row.notes}</p>
                  </div>
                ) : null}
              </article>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
