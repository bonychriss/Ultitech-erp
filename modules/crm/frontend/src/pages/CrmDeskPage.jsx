import { useCallback, useEffect, useRef, useState } from 'react';
import CustomerContactForm from '../components/CustomerContactForm.jsx';
import '../components/customer-form.css';
import { useBottomSheet } from '../hooks/useBottomSheet.js';
import {
  buildContactViewUrl,
  createContact,
  fetchContacts,
  getBootData,
} from '../api';

function emptyFormFromBoot(boot) {
  return { ...(boot?.defaults || {}) };
}

function statusBadgeClass(status) {
  return `crm-desk-badge crm-desk-badge-${status || 'lead'}`;
}

function statusLabel(status) {
  const labels = {
    lead: 'Lead',
    prospect: 'Prospect',
    customer: 'Client',
    inactive: 'Inactive',
  };
  return labels[status] || status;
}

function formatSourceLabel(source, maxLength = 10) {
  let text = String(source || '').trim();
  if (!text) return '-';
  if (/^CRM Market:/i.test(text)) {
    text = 'CRM Market';
  }
  if (text.length <= maxLength) return text;
  return `${text.slice(0, Math.max(1, maxLength - 3))}...`;
}

function IconSearch() {
  return (
    <svg className="crm-desk-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="11" cy="11" r="8" />
      <path d="m21 21-4.3-4.3" />
    </svg>
  );
}

function IconSparkles() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
      <path d="M20 3v4" />
      <path d="M22 5h-4" />
      <path d="M4 17v2" />
      <path d="M5 18H3" />
    </svg>
  );
}

function IconUsers({ className }) {
  return (
    <svg className={className} width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
      <circle cx="9" cy="7" r="4" />
      <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
      <path d="M16 3.13a4 4 0 0 1 0 7.75" />
    </svg>
  );
}

function IconUserPlus({ className }) {
  return (
    <svg className={className} width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
      <circle cx="9" cy="7" r="4" />
      <line x1="19" x2="19" y1="8" y2="14" />
      <line x1="22" x2="16" y1="11" y2="11" />
    </svg>
  );
}

function IconTarget({ className }) {
  return (
    <svg className={className} width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="10" />
      <circle cx="12" cy="12" r="6" />
      <circle cx="12" cy="12" r="2" />
    </svg>
  );
}

function IconUserCheck({ className }) {
  return (
    <svg className={className} width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
      <circle cx="9" cy="7" r="4" />
      <polyline points="16 11 18 13 22 9" />
    </svg>
  );
}

function IconFilter() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <line x1="4" x2="4" y1="21" y2="14" />
      <line x1="4" x2="4" y1="10" y2="3" />
      <line x1="12" x2="12" y1="21" y2="12" />
      <line x1="12" x2="12" y1="8" y2="3" />
      <line x1="20" x2="20" y1="21" y2="16" />
      <line x1="20" x2="20" y1="12" y2="3" />
      <line x1="2" x2="6" y1="14" y2="14" />
      <line x1="10" x2="14" y1="8" y2="8" />
      <line x1="18" x2="22" y1="16" y2="16" />
    </svg>
  );
}

function IconPlus() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M5 12h14" />
      <path d="M12 5v14" />
    </svg>
  );
}

function IconInbox() {
  return (
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <polyline points="22 12 16 12 14 15 10 15 8 12 2 12" />
      <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
    </svg>
  );
}

function KpiCard({ label, value, icon: Icon, tone, helper, href }) {
  const inner = (
    <>
      <div className={`crm-desk-kpi-icon crm-desk-kpi-icon--${tone}`}>
        <Icon />
      </div>
      <div>
        <div className="crm-desk-kpi-label">{label}</div>
        <div className="crm-desk-kpi-value">{value}</div>
        {helper ? <div className="crm-desk-kpi-helper">{helper}</div> : null}
      </div>
    </>
  );
  if (href) {
    return (
      <a className="crm-desk-kpi-card crm-desk-kpi-card--link" href={href}>
        {inner}
      </a>
    );
  }
  return <div className="crm-desk-kpi-card">{inner}</div>;
}

function ContactsEmptyState({ onAddContact }) {
  return (
    <div className="crm-desk-empty">
      <IconInbox />
      <p className="crm-desk-empty-title">No customers yet</p>
      <p className="crm-desk-empty-sub">Add your first client to get started.</p>
      <button type="button" className="crm-desk-btn crm-desk-btn-primary" style={{ marginTop: '1rem' }} onClick={onAddContact}>
        <IconPlus />
        Add client
      </button>
    </div>
  );
}

function IconClose() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.25" strokeLinecap="round" aria-hidden="true">
      <path d="M18 6 6 18" />
      <path d="m6 6 12 12" />
    </svg>
  );
}

function ContactModal({
  open,
  title,
  form,
  options,
  statuses,
  saving,
  error,
  onClose,
  onSave,
  onFieldChange,
}) {
  useEffect(() => {
    if (!open) return undefined;

    function onKeyDown(e) {
      if (e.key === 'Escape') onClose();
    }

    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKeyDown);

    return () => {
      document.body.style.overflow = '';
      window.removeEventListener('keydown', onKeyDown);
    };
  }, [open, onClose]);

  const { isMobileSheet, sheetStyle, sheetClassName, grabProps } = useBottomSheet({
    open,
    onClose,
  });

  if (!open) return null;

  return (
    <div className="crm-modal-overlay" onClick={onClose} role="presentation">
      <div
        className={`crm-modal${sheetClassName ? ` ${sheetClassName}` : ''}`}
        style={sheetStyle}
        role="dialog"
        aria-modal="true"
        aria-labelledby="crm-modal-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="crm-sheet-grab" {...(grabProps || {})}>
          {isMobileSheet && (
            <div className="crm-sheet-handle" aria-hidden="true">
              <span className="crm-sheet-handle-bar" />
            </div>
          )}
          <div className="crm-modal-header">
            <h3 id="crm-modal-title" className="crm-modal-title">{title}</h3>
            <button type="button" className="crm-modal-close" onClick={onClose} aria-label="Close">
              <IconClose />
            </button>
          </div>
        </div>

        <div className="crm-modal-form-wrap">
          <CustomerContactForm
            form={form}
            options={options}
            statuses={statuses}
            saving={saving}
            error={error}
            isNew
            onFieldChange={onFieldChange}
            onSubmit={onSave}
            onCancel={onClose}
            idPrefix="crm"
          />
        </div>
      </div>
    </div>
  );
}

export default function CrmDeskPage() {
  const boot = getBootData();
  const [contacts, setContacts] = useState(() => boot.contacts || []);
  const [stats, setStats] = useState(() => boot.stats || {});
  const [links] = useState(() => boot.links || {});
  const [options] = useState(() => boot.options || {});
  const [statuses] = useState(() => boot.statuses || []);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [draftStatusFilter, setDraftStatusFilter] = useState('all');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const filterWrapRef = useRef(null);
  const [form, setForm] = useState(() => emptyFormFromBoot(boot));
  const [modalOpen, setModalOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [modalError, setModalError] = useState('');
  const [pageError, setPageError] = useState('');
  const [success, setSuccess] = useState('');

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') === 'customers' || window.location.hash === '#my-clients') {
      const el = document.getElementById('my-clients');
      if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }
  }, []);

  useEffect(() => {
    if (!filtersOpen) return undefined;
    const onDown = (e) => {
      if (!filterWrapRef.current?.contains(e.target)) setFiltersOpen(false);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setFiltersOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [filtersOpen]);

  const loadContacts = useCallback(async (nextSearch = search, nextStatus = statusFilter) => {
    setLoading(true);
    setPageError('');
    try {
      const data = await fetchContacts(nextSearch, nextStatus);
      setContacts(data.contacts || []);
      setStats(data.stats || {});
    } catch (err) {
      setPageError(err.message || 'Failed to load contacts.');
    } finally {
      setLoading(false);
    }
  }, [search, statusFilter]);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadContacts(search, statusFilter);
    }, 250);
    return () => clearTimeout(timer);
  }, [search, statusFilter, loadContacts]);

  const filtersActive = statusFilter !== 'all';

  function applyFilters() {
    setStatusFilter(draftStatusFilter);
    setFiltersOpen(false);
  }

  function clearFilters() {
    setDraftStatusFilter('all');
    setStatusFilter('all');
    setFiltersOpen(false);
  }

  function submitSearch(e) {
    e.preventDefault();
    loadContacts(search, statusFilter);
  }

  function closeModal() {
    if (saving) return;
    setModalOpen(false);
    setModalError('');
    setForm(emptyFormFromBoot(boot));
  }

  function startNewContact() {
    setForm(emptyFormFromBoot(boot));
    setModalError('');
    setSuccess('');
    setModalOpen(true);
  }

  function goToContactView(contact) {
    window.location.href = buildContactViewUrl(contact.id, links);
  }

  function updateField(field, value) {
    setForm((prev) => {
      if (field === 'country') {
        return { ...prev, country: value, city: '' };
      }
      return { ...prev, [field]: value };
    });
  }

  async function handleSave(e) {
    e.preventDefault();
    if (!form.company_name?.trim() || !form.contact_person?.trim() || !form.source?.trim()) {
      setModalError('Company name, contact person, and source are required.');
      return;
    }

    setSaving(true);
    setModalError('');
    try {
      const data = await createContact(form);
      setContacts((prev) => [data.contact, ...prev]);
      setStats(data.stats || {});
      setSuccess('Contact created.');
      closeModal();
    } catch (err) {
      setModalError(err.message || 'Save failed.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="crm-desk-page">
      <div className="crm-desk-page-header">
        <form className="crm-desk-page-header-search" onSubmit={submitSearch} autoComplete="off">
          <div className="crm-desk-search-field">
            <IconSearch />
            <input
              type="search"
              className="crm-desk-search-input"
              placeholder="Search name, company, email, phone..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              aria-label="Search customers"
            />
          </div>
        </form>

        <div className="crm-desk-page-header-actions">
          <div className="crm-desk-filter-wrap" ref={filterWrapRef}>
            <button
              type="button"
              className={`crm-desk-filter-btn${filtersOpen ? ' is-active' : ''}`}
              onClick={() => {
                setDraftStatusFilter(statusFilter);
                setFiltersOpen((open) => !open);
              }}
              aria-expanded={filtersOpen}
              title="Filters"
            >
              <IconFilter />
              {filtersActive ? <span className="crm-desk-filter-dot" aria-hidden="true" /> : null}
            </button>
            {filtersOpen ? (
              <div className="crm-desk-filter-panel" role="dialog" aria-label="Customer filters">
                <div className="crm-desk-filter-grid">
                  <div>
                    <label htmlFor="crm-filter-status">Status</label>
                    <select
                      id="crm-filter-status"
                      value={draftStatusFilter}
                      onChange={(e) => setDraftStatusFilter(e.target.value)}
                    >
                      <option value="all">All statuses</option>
                      {statuses.map((status) => (
                        <option key={status.value || status} value={status.value || status}>
                          {status.label || statusLabel(status.value || status)}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
                <div className="crm-desk-filter-actions">
                  <button type="button" className="crm-desk-btn crm-desk-btn-secondary" onClick={clearFilters}>
                    Clear
                  </button>
                  <button type="button" className="crm-desk-btn crm-desk-btn-primary" onClick={applyFilters}>
                    Apply
                  </button>
                </div>
              </div>
            ) : null}
          </div>

          <button type="button" className="crm-desk-btn crm-desk-btn-primary" onClick={startNewContact}>
            <IconPlus />
            <span className="crm-desk-btn-label-desktop">Add client</span>
            <span className="crm-desk-btn-label-mobile">New</span>
          </button>
        </div>
      </div>

      {pageError ? <div className="crm-desk-alert crm-desk-alert-error">{pageError}</div> : null}
      {success ? <div className="crm-desk-alert crm-desk-alert-success">{success}</div> : null}

      <section className="crm-desk-kpi-grid" aria-label="Summary">
        <KpiCard label="total customers" value={stats.total ?? 0} icon={IconUsers} tone="violet" />
        <KpiCard label="leads" value={stats.lead ?? 0} icon={IconUserPlus} tone="amber" />
        <KpiCard
          label="prospects"
          value={stats.prospect ?? 0}
          icon={IconTarget}
          tone="indigo"
          href={links.prospectsList || '?module=crm&tab=prospects'}
        />
        <KpiCard
          label="listed now"
          value={contacts.length}
          icon={IconUserCheck}
          tone="teal"
          helper={loading ? 'loading...' : 'matching current filters'}
        />
      </section>

      <section className="crm-desk-results" id="my-clients">
        <div className="crm-desk-results-head">
          <span className="crm-desk-results-count">
            {contacts.length} {contacts.length === 1 ? 'result' : 'results'}
          </span>
        </div>

        {contacts.length === 0 && !loading ? (
          <ContactsEmptyState onAddContact={startNewContact} />
        ) : contacts.length === 0 ? (
          <div className="crm-desk-empty">
            <IconInbox />
            <p className="crm-desk-empty-title">Loading customers...</p>
          </div>
        ) : (
          <div className="crm-desk-table-wrap">
            <table className="crm-desk-table">
              <colgroup>
                <col className="crm-desk-col-company" />
                <col className="crm-desk-col-status" />
                <col className="crm-desk-col-email" />
                <col className="crm-desk-col-phone" />
                <col className="crm-desk-col-source" />
                <col className="crm-desk-col-amount" />
              </colgroup>
              <thead>
                <tr>
                  <th className="crm-desk-col-company">Company</th>
                  <th className="crm-desk-col-status">Status</th>
                  <th className="crm-desk-col-email">Email</th>
                  <th className="crm-desk-col-phone">Phone</th>
                  <th className="crm-desk-col-source">Src</th>
                  <th className="crm-desk-col-amount">Amount</th>
                </tr>
              </thead>
              <tbody>
                {contacts.map((contact) => (
                  <tr
                    key={contact.id}
                    onClick={() => goToContactView(contact)}
                  >
                    <td className="crm-desk-col-company">
                      <span className="crm-desk-name crm-desk-cell-text">
                        {contact.organization || '-'}
                      </span>
                      {contact.name ? (
                        <div className="crm-desk-muted">{contact.name}</div>
                      ) : null}
                    </td>
                    <td className="crm-desk-col-status">
                      <span className={statusBadgeClass(contact.status)}>
                        {statusLabel(contact.status)}
                      </span>
                    </td>
                    <td className="crm-desk-col-email" title={contact.email || ''}>
                      <span className="crm-desk-cell-text">{contact.email || '-'}</span>
                    </td>
                    <td className="crm-desk-col-phone">
                      <span className="crm-desk-cell-text">{contact.phone || '-'}</span>
                    </td>
                    <td className="crm-desk-col-source" title={contact.source || ''}>
                      <span className="crm-desk-cell-text">{formatSourceLabel(contact.source, 12)}</span>
                    </td>
                    <td className="crm-desk-col-amount">
                      {contact.invoice_amount && contact.invoice_amount !== '-' ? (
                        <span className="crm-desk-price crm-desk-cell-text">{contact.invoice_amount}</span>
                      ) : (
                        <span className="crm-desk-badge crm-desk-badge-new">New</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <ContactModal
        open={modalOpen}
        title="Add client"
        form={form}
        options={options}
        statuses={statuses}
        saving={saving}
        error={modalError}
        onClose={closeModal}
        onSave={handleSave}
        onFieldChange={updateField}
      />
    </div>
  );
}
