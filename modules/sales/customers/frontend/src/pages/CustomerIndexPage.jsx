import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from 'react';
import {
  Loader2,
  Pencil,
  Plus,
  Search,
  Users,
  X,
} from 'lucide-react';
import { fetchIndexInit } from '../api/catalogueDesk';
import CustomerAddModal from '../components/CustomerAddModal.jsx';

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

function getDeskCfg() {
  if (typeof window === 'undefined') return {};
  return window.__CUSTOMERS_DESK_CFG__ || window.__CUSTOMER_CATALOGUE_CFG__ || {};
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

function buildUrl(base, params) {
  const qs = new URLSearchParams(params).toString();
  const sep = base.includes('?') ? '&' : '?';
  return `${base}${sep}${qs}`;
}

function syncBrowserUrl(params) {
  const url = new URL(window.location.href);
  url.searchParams.delete('msg');
  url.searchParams.delete('page');
  if (params.module) url.searchParams.set('module', params.module);
  if (params.search) url.searchParams.set('search', params.search);
  else url.searchParams.delete('search');
  window.history.replaceState({}, '', url.toString());
}

function filterCustomers(customers, searchTerm) {
  const q = searchTerm.trim().toLowerCase();
  if (!q) return customers;
  return customers.filter((customer) => (
    (customer.company_name || '').toLowerCase().includes(q)
    || (customer.customer_code || '').toLowerCase().includes(q)
    || (customer.contact_person || '').toLowerCase().includes(q)
    || (customer.email || '').toLowerCase().includes(q)
    || (customer.phone || '').toLowerCase().includes(q)
  ));
}

export default function CustomerIndexPage() {
  const deskCfg = useMemo(() => getDeskCfg(), []);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [toast, setToast] = useState('');
  const [addModalOpen, setAddModalOpen] = useState(false);

  const loadData = useCallback(async (module) => {
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams();
      if (module) params.set('module', module);
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('msg') === 'created') {
        params.set('msg', 'created');
      }

      const payload = await fetchIndexInit(params);
      setData(payload);

      if (payload.show_created_toast) {
        setToast('Customer created successfully.');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load customers.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const module = params.get('module') || deskCfg.module || 'sales';
    setSearchTerm(params.get('search') || '');
    if (params.get('add') === '1') {
      setAddModalOpen(true);
    }
    loadData(module);
  }, [deskCfg.module, loadData]);

  useEffect(() => {
    if (!data) return;
    const module = data.module || deskCfg.module || 'sales';
    syncBrowserUrl({
      module,
      search: searchTerm.trim(),
    });
  }, [data, deskCfg.module, searchTerm]);

  useEffect(() => {
    if (!toast) return undefined;
    const timer = window.setTimeout(() => setToast(''), 2600);
    return () => window.clearTimeout(timer);
  }, [toast]);

  const allCustomers = data?.customers || [];
  const urls = data?.urls || {};
  const module = data?.module || deskCfg.module || 'sales';
  const filteredCustomers = useMemo(
    () => filterCustomers(allCustomers, searchTerm),
    [allCustomers, searchTerm],
  );

  function handleClearSearch() {
    setSearchTerm('');
  }

  function buildViewUrl(id) {
    return buildUrl(urls.view || 'view.php', { id, module });
  }

  function buildEditUrl(id) {
    return buildUrl(urls.edit || 'edit.php', { id, module });
  }

  function handleAddSuccess() {
    setAddModalOpen(false);
    setToast('Customer created successfully.');
    loadData(module);
  }

  if (loading && !data) {
    return (
      <div className="exp-desk-page exp-desk-boot-loading" role="status">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading customers...</span>
      </div>
    );
  }

  const trimmedSearch = searchTerm.trim();
  const resultsLabel = trimmedSearch
    ? `${filteredCustomers.length} ${filteredCustomers.length === 1 ? 'result' : 'results'} for "${trimmedSearch}"`
    : `${allCustomers.length} ${allCustomers.length === 1 ? 'customer' : 'customers'}`;

  return (
    <div className="exp-desk-page">
      {toast && (
        <div className="exp-desk-flash exp-desk-flash-success" role="status">{toast}</div>
      )}
      {error && (
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error}</div>
      )}

      <div className="exp-desk-page-header">
        <form
          className="exp-desk-page-header-search exp-desk-page-header-search--desktop"
          onSubmit={(e) => e.preventDefault()}
        >
          <div className="exp-desk-search-field">
            <Search className="exp-desk-search-icon" size={16} aria-hidden="true" />
            <input
              type="search"
              className="exp-desk-search-input"
              placeholder="Search by name, code or contact person..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
            {trimmedSearch && (
              <button
                type="button"
                className="exp-desk-search-clear"
                onClick={handleClearSearch}
                aria-label="Clear search"
              >
                <X size={14} aria-hidden="true" />
              </button>
            )}
          </div>
        </form>

        <div className="exp-desk-page-header-actions">
          {urls.crm && (
            <a href={urls.crm} className="exp-desk-btn exp-desk-btn-secondary">
              CRM
            </a>
          )}
          {urls.add && (
            <button
              type="button"
              className="exp-desk-btn exp-desk-btn-primary exp-desk-btn-create"
              onClick={() => setAddModalOpen(true)}
            >
              <Plus size={16} aria-hidden="true" />
              <span className="exp-desk-btn-label-desktop">Add client</span>
              <span className="exp-desk-btn-label-mobile">Add</span>
            </button>
          )}
        </div>
      </div>

      <section className="exp-desk-results ci-customers-table">
        <div className="exp-desk-results-head">
          <span className="exp-desk-results-count">{resultsLabel}</span>
        </div>

        {filteredCustomers.length === 0 ? (
          <div className="exp-desk-empty ci-empty ci-empty--animated">
            <div className="ci-empty-hero" aria-hidden="true">
              <span className="ci-empty-orbit ci-empty-orbit--1" />
              <span className="ci-empty-orbit ci-empty-orbit--2" />
              <span className="ci-empty-orbit ci-empty-orbit--3" />
              <span className="ci-empty-ring ci-empty-ring--1" />
              <span className="ci-empty-ring ci-empty-ring--2" />
              <span className="ci-empty-icon-wrap">
                <Users className="ci-empty-icon" />
              </span>
            </div>
            <p className="exp-desk-empty-title ci-empty-title">No customers found</p>
            <p className="exp-desk-empty-sub ci-empty-sub">
              {trimmedSearch ? 'Try a different search term.' : 'Add your first customer to get started.'}
            </p>
            {trimmedSearch && (
              <button
                type="button"
                className="exp-desk-btn exp-desk-btn-ghost ci-empty-action"
                onClick={handleClearSearch}
              >
                Clear search
              </button>
            )}
          </div>
        ) : (
          <div className="exp-desk-table-wrap">
            <table className="exp-desk-table">
              <thead>
                <tr>
                  <th className="ci-col-avatar" aria-label="Profile" />
                  <th>Code</th>
                  <th>Company</th>
                  <th>Contact</th>
                  <th>Type</th>
                  <th className="exp-desk-row-actions ci-col-action">Action</th>
                </tr>
              </thead>
              <tbody>
                {filteredCustomers.map((customer) => {
                  const style = avatarStyle(customer.id);
                  const viewUrl = buildViewUrl(customer.id);
                  const editUrl = buildEditUrl(customer.id);
                  return (
                    <tr
                      key={customer.id}
                      className="exp-desk-row-clickable"
                      tabIndex={0}
                      onClick={() => { window.location.href = viewUrl; }}
                      onKeyDown={(event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                          event.preventDefault();
                          window.location.href = viewUrl;
                        }
                      }}
                    >
                      <td className="ci-col-avatar">
                        <span className="ci-avatar" style={style}>
                          {getInitials(customer.company_name)}
                        </span>
                      </td>
                      <td>
                        <span className="exp-desk-ref">{cellOrDash(customer.customer_code)}</span>
                      </td>
                      <td>
                        <span className="exp-desk-cell-main">{cellOrDash(customer.company_name)}</span>
                        {customer.email ? (
                          <div className="exp-desk-cell-sub">{customer.email}</div>
                        ) : null}
                      </td>
                      <td>
                        <span className="exp-desk-cell-main">{cellOrDash(customer.contact_person)}</span>
                        {customer.phone ? (
                          <div className="exp-desk-cell-sub">{customer.phone}</div>
                        ) : null}
                      </td>
                      <td>
                        <span className="ci-type-badge">{cellOrDash(customer.customer_type)}</span>
                      </td>
                      <td
                        className="exp-desk-row-actions ci-col-action"
                        onClick={(e) => e.stopPropagation()}
                        data-exp-row-ignore
                      >
                        <div className="exp-desk-row-actions-inner">
                          <a
                            href={editUrl}
                            className="exp-desk-row-action"
                            title="Edit customer"
                            aria-label="Edit customer"
                          >
                            <Pencil size={15} aria-hidden="true" />
                            <span className="exp-desk-row-action-label">Edit</span>
                          </a>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <CustomerAddModal
        open={addModalOpen}
        idPrefix="ca-index"
        onClose={() => setAddModalOpen(false)}
        onSuccess={handleAddSuccess}
      />
    </div>
  );
}
