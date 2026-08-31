import { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { fetchProspects, getBootData, importMarketLead } from '../api';

function IconSearch() {
  return (
    <svg className="crm-desk-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="11" cy="11" r="8" />
      <path d="m21 21-4.3-4.3" />
    </svg>
  );
}

function IconDots() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <circle cx="12" cy="5" r="1.7" />
      <circle cx="12" cy="12" r="1.7" />
      <circle cx="12" cy="19" r="1.7" />
    </svg>
  );
}

function IconUserPlus() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
      <circle cx="9" cy="7" r="4" />
      <path d="M19 8v6M22 11h-6" />
    </svg>
  );
}

function IconInbox() {
  return (
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <polyline points="22 12 16 12 14 15 10 15 8 12 2 12" />
      <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
    </svg>
  );
}

function cleanField(value) {
  const text = String(value ?? '')
    .replace(/\uFFFD/g, '')
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
  if (!text || text === '?' || /^[?]+$/.test(text)) return '';
  return text;
}

function displayField(value) {
  return cleanField(value) || '-';
}

function assigneeNameClass(name, id) {
  const label = cleanField(name);
  if (!label || /^unassigned$/i.test(label)) {
    return 'crm-desk-assigned-name crm-desk-assigned-name--muted';
  }
  const key = id != null && Number(id) > 0 ? `id:${id}` : label.toLowerCase();
  let h = 0;
  for (let i = 0; i < key.length; i += 1) {
    h = (h * 31 + key.charCodeAt(i)) >>> 0;
  }
  return `crm-desk-assigned-name crm-desk-assigned-name--t${h % 6}`;
}

export default function CrmProspectsPage() {
  const boot = getBootData();
  const links = boot.links || {};
  const customersUrl =
    links.customersList ||
    links.dashboard ||
    links.page ||
    '/modules/crm/my-clients/index?module=crm&tab=customers';

  const [search, setSearch] = useState('');
  const [leads, setLeads] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [busyId, setBusyId] = useState('');
  const [openMenuId, setOpenMenuId] = useState('');
  const [menuPos, setMenuPos] = useState({ top: 0, left: 0 });

  const load = useCallback(async (q = search) => {
    setLoading(true);
    setError('');
    try {
      const data = await fetchProspects(q);
      setLeads(Array.isArray(data?.leads) ? data.leads : []);
    } catch (e) {
      setError(e.message || 'Failed to load prospects.');
      setLeads([]);
    } finally {
      setLoading(false);
    }
  }, [search]);

  useEffect(() => {
    void load('');
  }, []);

  useEffect(() => {
    if (!openMenuId) return undefined;
    function close(e) {
      if (e.target.closest?.('[data-prospect-actions]')) return;
      setOpenMenuId('');
    }
    function onKey(ev) {
      if (ev.key === 'Escape') setOpenMenuId('');
    }
    function onReposition() {
      setOpenMenuId('');
    }
    document.addEventListener('click', close);
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', onReposition);
    window.addEventListener('scroll', onReposition, true);
    return () => {
      document.removeEventListener('click', close);
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('resize', onReposition);
      window.removeEventListener('scroll', onReposition, true);
    };
  }, [openMenuId]);

  function toggleMenu(e, leadId) {
    e.stopPropagation();
    if (openMenuId === leadId) {
      setOpenMenuId('');
      return;
    }
    const rect = e.currentTarget.getBoundingClientRect();
    const width = 180;
    const left = Math.min(Math.max(8, rect.right - width), window.innerWidth - width - 8);
    const top = Math.min(rect.bottom + 6, window.innerHeight - 56);
    setMenuPos({ top, left });
    setOpenMenuId(leadId);
  }

  function onSearch(e) {
    e.preventDefault();
    void load(search);
  }

  async function addToCustomers(lead) {
    if (!lead?.id || lead.imported || busyId) return;
    setOpenMenuId('');
    setBusyId(lead.id);
    setError('');
    setMessage('');
    try {
      const data = await importMarketLead(lead.id);
      setLeads((prev) => prev.map((row) => (row.id === lead.id ? { ...row, imported: true } : row)));
      setMessage(
        data?.promoted
          ? 'Moved to My Customers.'
          : data?.created === false
            ? 'Already in My Customers.'
            : 'Added to My Customers.'
      );
    } catch (e) {
      setError(e.message || 'Could not add to My Customers.');
    } finally {
      setBusyId('');
    }
  }

  return (
    <div className="crm-desk-page">
      <div className="crm-desk-page-header">
        <form className="crm-desk-page-header-search" onSubmit={onSearch} autoComplete="off">
          <div className="crm-desk-search-field">
            <IconSearch />
            <input
              className="crm-desk-search-input"
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search prospects..."
              aria-label="Search prospects"
            />
          </div>
        </form>
        <div className="crm-desk-page-header-actions">
          <a className="crm-desk-btn crm-desk-btn-secondary" href={customersUrl}>
            Back to customers
          </a>
        </div>
      </div>

      {error ? <div className="crm-desk-alert crm-desk-alert-error">{error}</div> : null}
      {message ? <div className="crm-desk-alert crm-desk-alert-success">{message}</div> : null}

      <section className="crm-desk-results">
        <div className="crm-desk-results-head">
          <span className="crm-desk-results-count">
            {leads.length} {leads.length === 1 ? 'prospect' : 'prospects'}
            {loading ? ' ? loading' : ''}
          </span>
        </div>

        {!loading && leads.length === 0 ? (
          <div className="crm-desk-empty crm-desk-empty--prospects" role="status">
            <span className="crm-desk-empty-orb" aria-hidden="true">
              <IconInbox />
            </span>
            <p className="crm-desk-empty-title">No prospects yet</p>
            <p className="crm-desk-empty-sub">Run a Search in CRM Market. Companies are split across sales users and appear here on Prospects for each assignee.</p>
          </div>
        ) : (
          <div className="crm-desk-table-wrap">
            <table className="crm-desk-table">
              <thead>
                <tr>
                  <th>Company</th>
                  <th>Category</th>
                  <th>Location</th>
                  <th>Email</th>
                  <th>Phone</th>
                  <th>Assigned to</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {leads.map((lead) => (
                  <tr key={lead.id}>
                    <td>
                      <span className="crm-desk-name crm-desk-cell-text">{displayField(lead.name)}</span>
                      {cleanField(lead.keyword) ? <div className="crm-desk-muted">{cleanField(lead.keyword)}</div> : null}
                    </td>
                    <td>{displayField(lead.category)}</td>
                    <td>{displayField(lead.location)}</td>
                    <td className="crm-desk-cell-text crm-desk-prospect-email">{displayField(lead.email)}</td>
                    <td className="crm-desk-prospect-phone">{displayField(lead.phone)}</td>
                    <td>
                      <span className={assigneeNameClass(lead.assigned_user_name, lead.assigned_to)}>
                        {cleanField(lead.assigned_user_name) || 'Unassigned'}
                      </span>
                    </td>
                    <td className="crm-desk-actions-cell">
                      <div className="crm-desk-actions-menu" data-prospect-actions="1">
                        <button
                          type="button"
                          className="crm-desk-actions-dots"
                          aria-label="Actions"
                          aria-expanded={openMenuId === lead.id}
                          onClick={(e) => toggleMenu(e, lead.id)}
                        >
                          <IconDots />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
      {openMenuId
        ? createPortal(
            <div
              className="crm-desk-actions-dropdown"
              data-prospect-actions="1"
              role="menu"
              style={{ top: menuPos.top, left: menuPos.left }}
            >
              <button
                type="button"
                className="crm-desk-actions-item"
                role="menuitem"
                disabled={!!leads.find((row) => row.id === openMenuId)?.imported || busyId === openMenuId}
                onClick={(e) => {
                  e.stopPropagation();
                  const lead = leads.find((row) => row.id === openMenuId);
                  if (lead) void addToCustomers(lead);
                }}
              >
                {leads.find((row) => row.id === openMenuId)?.imported ? (
                  <>
                    <IconUserPlus />
                    Already added
                  </>
                ) : busyId === openMenuId ? (
                  <>
                    <IconUserPlus />
                    Adding...
                  </>
                ) : (
                  <>
                    <IconUserPlus />
                    Add customer
                  </>
                )}
              </button>
            </div>,
            document.body
          )
        : null}
    </div>
  );
}
