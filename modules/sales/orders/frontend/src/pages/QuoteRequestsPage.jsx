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

const LIST_STATE_KEY = 'qr-list-state';

function readListState() {
  const params = new URLSearchParams(window.location.search);
  const fromUrl = {
    search: params.get('q') || '',
    opened: params.get('sel') || '',
  };
  if (fromUrl.search || fromUrl.opened) return fromUrl;
  try {
    const saved = JSON.parse(window.sessionStorage.getItem(LIST_STATE_KEY) || 'null');
    window.sessionStorage.removeItem(LIST_STATE_KEY);
    if (saved && typeof saved === 'object') {
      return {
        search: String(saved.search || ''),
        opened: String(saved.opened || ''),
      };
    }
  } catch {
    /* storage can be blocked */
  }
  return fromUrl;
}

function writeListStateToUrl(state) {
  const url = new URL(window.location.href);
  const setOrDel = (key, value) => {
    const v = String(value || '').trim();
    if (v) url.searchParams.set(key, v);
    else url.searchParams.delete(key);
  };
  setOrDel('q', state.search);
  setOrDel('sel', state.opened);
  const next = url.pathname + url.search + url.hash;
  const cur = window.location.pathname + window.location.search + window.location.hash;
  if (next !== cur) window.history.replaceState(window.history.state, '', next);
}

function saveListStateForReturn(state) {
  try {
    window.sessionStorage.setItem(LIST_STATE_KEY, JSON.stringify(state));
  } catch {
    /* storage can be blocked */
  }
}

function ensureDotLottiePlayer() {
  if (typeof document === 'undefined') return;
  if (document.getElementById('qr-dotlottie-wc')) return;
  const script = document.createElement('script');
  script.id = 'qr-dotlottie-wc';
  script.type = 'module';
  script.src = 'https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.5/dist/dotlottie-wc.js';
  document.head.appendChild(script);
}

function EmptyQuoteRequests({ animationSrc }) {
  useEffect(() => {
    ensureDotLottiePlayer();
  }, []);

  return (
    <div className="exp-desk-empty qr-desk-empty">
      {animationSrc ? (
        <div className="qr-desk-empty-anim" aria-hidden="true">
          <dotlottie-wc
            src={animationSrc}
            autoplay
            loop
            speed="1"
            style={{ width: '220px', height: '220px' }}
          />
        </div>
      ) : null}
      <p className="exp-desk-empty-title">No quote requests found</p>
      <p>Website quotation requests will appear here.</p>
    </div>
  );
}

export default function QuoteRequestsPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [restored] = useState(() => readListState());
  const [search, setSearch] = useState(restored.search);
  const [openedKey, setOpenedKey] = useState(restored.opened);

  useEffect(() => {
    writeListStateToUrl({ search, opened: openedKey });
  }, [search, openedKey]);

  useEffect(() => {
    const save = () => saveListStateForReturn({ search, opened: openedKey });
    window.addEventListener('pagehide', save);
    return () => window.removeEventListener('pagehide', save);
  }, [search, openedKey]);

  useEffect(() => {
    if (!init || !openedKey) return undefined;
    const timer = window.setTimeout(() => {
      const card = Array.from(document.querySelectorAll('[data-quote-request]'))
        .find((el) => el.getAttribute('data-quote-request') === openedKey);
      if (card && typeof card.scrollIntoView === 'function') {
        card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    }, 80);
    return () => window.clearTimeout(timer);
  }, [init, openedKey]);

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
  const nothingSrc = init?.nothing_animation || '/assets/animations/nothing.lottie';

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

      <section className="exp-desk-kpi-grid so-kpi-grid so-kpi-grid--compact so-kpi-grid--slim" aria-label="Quote request summary">
        <article className="so-kpi so-kpi--value" title="Quote requests sent from the website">
          <div className="so-kpi-top">
            <span className="so-kpi-label">Website requests</span>
            <span className="so-kpi-icon"><Inbox size={16} aria-hidden="true" /></span>
          </div>
          <strong className="so-kpi-value">{requests.length}</strong>
        </article>
        <article className="so-kpi so-kpi--listed" title="Matching current search">
          <div className="so-kpi-top">
            <span className="so-kpi-label">Listed now</span>
            <span className="so-kpi-icon"><Search size={16} aria-hidden="true" /></span>
          </div>
          <strong className="so-kpi-value">{filtered.length}</strong>
        </article>
      </section>

      <section className="exp-desk-results">
        <div className="exp-desk-results-meta">
          {filtered.length} {filtered.length === 1 ? 'result' : 'results'}
        </div>

        {filtered.length === 0 ? (
          <EmptyQuoteRequests animationSrc={nothingSrc} />
        ) : (
          <div className="qr-desk-list">
            {filtered.map((row) => (
              <article
                key={row.quote_number}
                data-quote-request={row.quote_number}
                aria-current={openedKey === row.quote_number ? 'true' : undefined}
                className={`qr-desk-card${openedKey === row.quote_number ? ' is-opened' : ''}`}
                onClick={() => setOpenedKey(row.quote_number)}
              >
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
