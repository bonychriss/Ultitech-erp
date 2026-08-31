import { useMemo } from 'react';
import CustomerSalesDocs from '../components/CustomerSalesDocs.jsx';
import { getBootData } from '../api';

function IconBack() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="m15 18-6-6 6-6" />
    </svg>
  );
}

function IconMail() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <rect width="20" height="16" x="2" y="4" rx="2" />
      <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
    </svg>
  );
}

function IconPhone() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
    </svg>
  );
}

function IconSource() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="10" />
      <path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20" />
      <path d="M2 12h20" />
    </svg>
  );
}

function MetaItem({ icon, tone, label, value }) {
  return (
    <div className="crm-view-meta-item">
      <div className="crm-view-meta-head">
        <span className={`crm-view-meta-icon crm-view-meta-icon--${tone}`}>{icon}</span>
        <span className="crm-view-meta-label">{label}</span>
      </div>
      <span className="crm-view-meta-value">{value}</span>
    </div>
  );
}

export default function CrmContactViewPage() {
  const boot = useMemo(() => getBootData(), []);
  const contact = boot.contact || {};
  const sales = boot.sales || null;
  const links = boot.links || {};

  const customersListUrl = links.customersList || links.dashboard || links.page || '/modules/crm/my-clients/index.php?module=crm&tab=customers';

  return (
    <div className="crm-page crm-page--view">
      <div className="crm-view-actions">
        <a className="crm-link-back crm-view-back" href={customersListUrl}>
          <IconBack />
          Back to customers
        </a>
        {sales?.urls?.new_quote ? (
          <a className="ms-btn ms-btn--purple" href={sales.urls.new_quote} target="_blank" rel="noreferrer">
            + New quote
          </a>
        ) : null}
      </div>

      {(contact.email || contact.phone || contact.source) && (
        <div className="dash-card crm-view-meta-card mb-3">
          <div className="crm-view-meta">
            {contact.email && (
              <MetaItem icon={<IconMail />} tone="email" label="Email" value={contact.email} />
            )}
            {contact.phone && (
              <MetaItem icon={<IconPhone />} tone="phone" label="Phone" value={contact.phone} />
            )}
            {contact.source && (
              <MetaItem icon={<IconSource />} tone="source" label="Source" value={contact.source} />
            )}
          </div>
        </div>
      )}

      <CustomerSalesDocs sales={sales} loading={false} />
    </div>
  );
}
