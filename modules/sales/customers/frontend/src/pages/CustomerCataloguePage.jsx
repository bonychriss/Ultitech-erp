import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from 'react';
import {
  ArrowLeft,
  ChevronLeft,
  ChevronRight,
  Grid3x3,
  LayoutList,
  Loader2,
  Search,
  UserCheck,
  Users,
} from 'lucide-react';
import { fetchCatalogueInit } from '../api/catalogueDesk';

const PAGE_SIZE_OPTIONS = [12, 24, 48];
const PAGINATION_WINDOW = 5;

const AVATAR_STYLES = [
  { background: '#e0f2fe', color: '#0369a1' },
  { background: '#ede9fe', color: '#6d28d9' },
  { background: '#d1fae5', color: '#047857' },
  { background: '#fef3c7', color: '#b45309' },
  { background: '#ffe4e6', color: '#be123c' },
  { background: '#cffafe', color: '#0e7490' },
  { background: '#e0e7ff', color: '#4338ca' },
  { background: '#ccfbf1', color: '#0f766e' },
];

function paginationPageNumbers(currentPage, totalPages, windowSize = PAGINATION_WINDOW) {
  if (totalPages <= 1) return totalPages >= 1 ? [1] : [];
  if (totalPages <= windowSize) {
    return Array.from({ length: totalPages }, (_, i) => i + 1);
  }
  const block = Math.floor((currentPage - 1) / windowSize);
  const start = block * windowSize + 1;
  const end = Math.min(start + windowSize - 1, totalPages);
  return Array.from({ length: end - start + 1 }, (_, i) => start + i);
}

function getInitials(name) {
  const s = (name || '').trim();
  if (!s) return 'CU';
  const words = s.split(/\s+/).filter(Boolean);
  if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
  return (words[0][0] + words[1][0]).toUpperCase();
}

function avatarStyle(seed) {
  const str = String(seed ?? '');
  let h = 0;
  for (let i = 0; i < str.length; i += 1) {
    h = (h * 31 + str.charCodeAt(i)) >>> 0;
  }
  return AVATAR_STYLES[h % AVATAR_STYLES.length];
}

function cellOrDash(value) {
  const text = value == null ? '' : String(value).trim();
  return text || '-';
}

function buildViewUrl(base, id, module) {
  const params = new URLSearchParams();
  params.set('id', String(id));
  if (module) params.set('module', module);
  const sep = base.includes('?') ? '&' : '?';
  return `${base}${sep}${params.toString()}`;
}

function CustomerCard({
  customer,
  selected,
  onToggle,
  viewUrl,
}) {
  const style = avatarStyle(customer.id);
  return (
    <article
      className={`cc-card${selected ? ' is-selected' : ''}`}
      onClick={() => onToggle(customer.id)}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          onToggle(customer.id);
        }
      }}
      role="button"
      tabIndex={0}
    >
      <div className="cc-card-check" onClick={(e) => e.stopPropagation()} aria-hidden="true">
        <input
          type="checkbox"
          className="cc-checkbox"
          checked={selected}
          onChange={() => onToggle(customer.id)}
          aria-label={`Select ${customer.company_name}`}
        />
      </div>
      <span className="cc-card-code">{customer.customer_code || 'N/A'}</span>
      <div className="cc-card-avatar-wrap">
        <div className="cc-card-avatar" style={style}>{getInitials(customer.company_name)}</div>
      </div>
      <h3 className="cc-card-title">{customer.company_name}</h3>
      {customer.contact_person ? (
        <p className="cc-card-contact">{customer.contact_person}</p>
      ) : null}
      {customer.invoice_count > 0 ? (
        <span className="cc-card-badge">{customer.invoice_count} invoices (6 mo)</span>
      ) : null}
      <div className="cc-card-foot">
        <a
          href={viewUrl}
          className="cc-link"
          onClick={(e) => e.stopPropagation()}
        >
          View details
        </a>
      </div>
    </article>
  );
}

function CustomerListRow({
  customer,
  selected,
  onToggle,
  viewUrl,
}) {
  const style = avatarStyle(customer.id);
  return (
    <tr className={selected ? 'is-selected' : ''} onClick={() => onToggle(customer.id)}>
      <td className="cc-col-check" onClick={(e) => e.stopPropagation()}>
        <input
          type="checkbox"
          className="cc-checkbox"
          checked={selected}
          onChange={() => onToggle(customer.id)}
          aria-label={`Select ${customer.company_name}`}
        />
      </td>
      <td className="cc-col-avatar">
        <div className="cc-row-avatar" style={style}>{getInitials(customer.company_name)}</div>
      </td>
      <td className="cc-col-code">{cellOrDash(customer.customer_code)}</td>
      <td className="cc-col-company">{customer.company_name}</td>
      <td>{cellOrDash(customer.contact_person)}</td>
      <td>{cellOrDash(customer.phone)}</td>
      <td className="cc-col-email">{cellOrDash(customer.email)}</td>
      <td className="cc-col-action">
        <a href={viewUrl} className="cc-link" onClick={(e) => e.stopPropagation()}>Details</a>
      </td>
    </tr>
  );
}

export default function CustomerCataloguePage() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [data, setData] = useState(null);

  const [searchTerm, setSearchTerm] = useState('');
  const [viewMode, setViewMode] = useState('grid');
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(24);
  const [selectedIds, setSelectedIds] = useState({});

  const loadData = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams(window.location.search);
      const payload = await fetchCatalogueInit(params);
      setData(payload);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load customer catalogue.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const customers = data?.customers || [];
  const context = data?.context || {};
  const urls = data?.urls || {};
  const module = data?.module || 'sales';
  const multiSelect = !!context.multi_select;

  useEffect(() => {
    if (!multiSelect) return;
    try {
      const saved = JSON.parse(localStorage.getItem('selected_customer_ids') || '[]');
      if (Array.isArray(saved)) {
        const map = {};
        saved.forEach((id) => {
          if (id) map[Number(id)] = true;
        });
        setSelectedIds(map);
      }
    } catch {
      /* ignore */
    }
  }, [multiSelect]);

  useEffect(() => {
    setPage(1);
  }, [searchTerm, pageSize]);

  const filteredCustomers = useMemo(() => {
    let list = [...customers];
    const q = searchTerm.trim().toLowerCase();
    if (q) {
      list = list.filter((c) => (
        (c.company_name || '').toLowerCase().includes(q)
        || (c.contact_person || '').toLowerCase().includes(q)
        || (c.customer_code || '').toLowerCase().includes(q)
        || (c.email || '').toLowerCase().includes(q)
        || (c.phone || '').toLowerCase().includes(q)
      ));
    }
    list.sort((a, b) => (a.company_name || '').localeCompare(b.company_name || ''));
    return list;
  }, [customers, searchTerm]);

  const pageCount = Math.max(1, Math.ceil(filteredCustomers.length / pageSize));
  const safePage = Math.min(page, pageCount);
  const pageNumbers = useMemo(
    () => paginationPageNumbers(safePage, pageCount),
    [safePage, pageCount],
  );
  const pagedCustomers = useMemo(() => {
    const start = (safePage - 1) * pageSize;
    return filteredCustomers.slice(start, start + pageSize);
  }, [filteredCustomers, safePage, pageSize]);

  const selectedList = useMemo(
    () => Object.keys(selectedIds).filter((k) => selectedIds[k]).map(Number),
    [selectedIds],
  );
  const totalSelected = selectedList.length;

  function handleToggle(id) {
    const numId = Number(id);
    if (!numId) return;
    setSelectedIds((prev) => {
      if (multiSelect) {
        const next = { ...prev };
        if (next[numId]) delete next[numId];
        else next[numId] = true;
        return next;
      }
      return prev[numId] ? {} : { [numId]: true };
    });
  }

  function redirectWithSelected(ids) {
    const returnUrl = urls.return || '/';
    try {
      const url = new URL(returnUrl, window.location.origin);
      url.searchParams.delete('customer_id');
      url.searchParams.delete('customer_ids[]');
      url.searchParams.delete('customer_ids');
      ids.forEach((cid) => url.searchParams.append('customer_ids[]', String(cid)));
      window.location.href = url.toString();
    } catch {
      const qs = ids.map((id) => `customer_ids%5B%5D=${encodeURIComponent(String(id))}`).join('&');
      const joiner = returnUrl.includes('?') ? '&' : '?';
      window.location.href = `${returnUrl}${joiner}${qs}`;
    }
  }

  function handleSendToDoc() {
    if (totalSelected === 0) {
      window.alert('Please select at least one customer.');
      return;
    }
    if (multiSelect) {
      localStorage.setItem('selected_customer_ids', JSON.stringify(selectedList));
      redirectWithSelected(selectedList);
      return;
    }
    const id = selectedList[0];
    localStorage.setItem('selected_customer_id', String(id));
    const returnUrl = urls.return || '/';
    try {
      const url = new URL(returnUrl, window.location.origin);
      url.searchParams.set('customer_id', String(id));
      window.location.href = url.toString();
    } catch {
      const joiner = returnUrl.includes('?') ? '&' : '?';
      window.location.href = `${returnUrl}${joiner}customer_id=${encodeURIComponent(String(id))}`;
    }
  }

  const rangeStart = filteredCustomers.length === 0 ? 0 : (safePage - 1) * pageSize + 1;
  const rangeEnd = Math.min(safePage * pageSize, filteredCustomers.length);
  const lastPageInWindow = pageNumbers[pageNumbers.length - 1] || 0;

  if (loading && !data) {
    return (
      <div className="exp-desk-page exp-desk-boot-loading" role="status">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading customer catalogue...</span>
      </div>
    );
  }

  return (
    <div className="exp-desk-page cc-page">
      {error && (
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error}</div>
      )}

      <div className="exp-desk-page-header cc-page-header">
        <div className="cc-page-header-back">
          <a href={urls.return || '#'} className="cc-back-btn" title="Back">
            <ArrowLeft size={18} aria-hidden="true" />
          </a>
        </div>
        <div className="exp-desk-page-header-search exp-desk-page-header-search--desktop cc-page-header-search">
          <div className="exp-desk-search-field cc-search-field">
            <Search className="exp-desk-search-icon" size={16} aria-hidden="true" />
            <input
              id="cc-search"
              type="search"
              className="exp-desk-search-input"
              placeholder="Search by company, contact, code, phone or email..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
          </div>
        </div>
        <div className="exp-desk-page-header-actions">
          <button
            type="button"
            className="exp-desk-btn exp-desk-btn-primary cc-add-btn"
            onClick={handleSendToDoc}
          >
            <UserCheck size={16} aria-hidden="true" />
            Add selected (
            {totalSelected}
            )
          </button>
        </div>
      </div>

      <div className="cc-search-mobile">
        <div className="exp-desk-search-field cc-search-field">
          <Search className="exp-desk-search-icon" size={16} aria-hidden="true" />
          <input
            type="search"
            className="exp-desk-search-input"
            placeholder="Search customers..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            aria-label="Search customers"
          />
        </div>
      </div>

      <div className="cc-toolbar">
        <p className="cc-count">
          <strong>{filteredCustomers.length}</strong>
          {' '}
          shown /
          {' '}
          <strong>{customers.length}</strong>
          {' '}
          active
        </p>
        <div className="cc-view-toggle" role="group" aria-label="View mode">
          <button
            type="button"
            className={viewMode === 'grid' ? 'is-active' : ''}
            onClick={() => setViewMode('grid')}
            aria-pressed={viewMode === 'grid'}
            title="Grid view"
          >
            <Grid3x3 size={16} aria-hidden="true" />
          </button>
          <button
            type="button"
            className={viewMode === 'list' ? 'is-active' : ''}
            onClick={() => setViewMode('list')}
            aria-pressed={viewMode === 'list'}
            title="List view"
          >
            <LayoutList size={16} aria-hidden="true" />
          </button>
        </div>
      </div>

      {filteredCustomers.length === 0 ? (
        <div className="exp-desk-empty cc-empty">
          <div className="cc-empty-icon exp-desk-kpi-icon exp-desk-kpi-icon--indigo" aria-hidden="true">
            <Users size={24} />
          </div>
          <p className="exp-desk-empty-title">No customers found</p>
          <p className="exp-desk-empty-sub">Try adjusting your search.</p>
          {searchTerm && (
            <button type="button" className="exp-desk-btn exp-desk-btn-ghost" onClick={() => setSearchTerm('')}>
              Clear search
            </button>
          )}
        </div>
      ) : viewMode === 'list' ? (
        <section className="cc-section cc-table-wrap">
          <div className="cc-table-scroll">
            <table className="cc-table">
              <thead>
                <tr>
                  <th className="cc-col-check" aria-label="Select" />
                  <th className="cc-col-avatar" aria-label="Avatar" />
                  <th>Code</th>
                  <th>Company</th>
                  <th>Contact</th>
                  <th>Phone</th>
                  <th>Email</th>
                  <th className="cc-col-action" />
                </tr>
              </thead>
              <tbody>
                {pagedCustomers.map((customer) => (
                  <CustomerListRow
                    key={customer.id}
                    customer={customer}
                    selected={!!selectedIds[customer.id]}
                    onToggle={handleToggle}
                    viewUrl={buildViewUrl(urls.customer_view, customer.id, module)}
                  />
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ) : (
        <div className="cc-grid">
          {pagedCustomers.map((customer) => (
            <CustomerCard
              key={customer.id}
              customer={customer}
              selected={!!selectedIds[customer.id]}
              onToggle={handleToggle}
              viewUrl={buildViewUrl(urls.customer_view, customer.id, module)}
            />
          ))}
        </div>
      )}

      {filteredCustomers.length > 0 && (
        <div className="cc-pagination">
          <p className="cc-pagination-range">
            Showing
            {' '}
            {rangeStart}
            {' '}
            to
            {' '}
            {rangeEnd}
            {' '}
            of
            {' '}
            {filteredCustomers.length}
          </p>
          <div className="cc-pagination-controls">
            <button
              type="button"
              className="cc-page-btn"
              disabled={safePage <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              aria-label="Previous page"
            >
              <ChevronLeft size={16} aria-hidden="true" />
            </button>
            {pageNumbers.map((pn) => (
              <button
                key={pn}
                type="button"
                className={`cc-page-btn${pn === safePage ? ' is-active' : ''}`}
                onClick={() => setPage(pn)}
              >
                {pn}
              </button>
            ))}
            {pageCount > lastPageInWindow && (
              <>
                <span className="cc-page-btn cc-page-ellipsis">...</span>
                <button type="button" className="cc-page-btn" onClick={() => setPage(pageCount)}>
                  {pageCount}
                </button>
              </>
            )}
            <button
              type="button"
              className="cc-page-btn"
              disabled={safePage >= pageCount}
              onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
              aria-label="Next page"
            >
              <ChevronRight size={16} aria-hidden="true" />
            </button>
          </div>
          <div className="cc-page-size">
            <select
              value={pageSize}
              onChange={(e) => setPageSize(Number(e.target.value))}
              aria-label="Results per page"
            >
              {PAGE_SIZE_OPTIONS.map((n) => (
                <option key={n} value={n}>{n} per page</option>
              ))}
            </select>
          </div>
        </div>
      )}
    </div>
  );
}
