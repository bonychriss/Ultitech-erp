import React from 'react';
import {
  HiOutlineArrowLeft,
  HiOutlineChatBubbleLeftRight,
  HiOutlineEnvelope,
  HiOutlinePhone,
  HiOutlinePlus,
} from 'react-icons/hi2';
import './quote-request-detail.css';

function initials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

function Action({ href, icon: Icon, label, primary = false, external = false }) {
  if (!href) return null;
  return (
    <a
      className={`qrd-action${primary ? ' qrd-action--primary' : ''}`}
      href={href}
      {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
    >
      <Icon aria-hidden="true" />
      <span>{label}</span>
    </a>
  );
}

export default function QuoteRequestDetail({ data }) {
  const { found = false, backUrl = '', createUrl = '', quote = null } = data;

  const back = (
    <a className="qrd-back" href={backUrl}>
      <HiOutlineArrowLeft aria-hidden="true" />
      <span>All quote requests</span>
    </a>
  );

  if (!found || !quote) {
    return (
      <div className="qrd">
        {back}
        <div className="qrd-card qrd-empty">This quote request was not found. It may have been removed.</div>
      </div>
    );
  }

  const { customer = {}, items = [], links = {} } = quote;
  const contact = [customer.phone, customer.email].filter(Boolean);

  return (
    <div className="qrd">
      {back}

      <header className="qrd-head">
        <div>
          <div className="qrd-title">
            <h1>{quote.number}</h1>
            <span className={`qrd-status qrd-status--${quote.statusKey}`}>{quote.statusLabel}</span>
          </div>
          <p className="qrd-meta">Received {quote.receivedAt} from ultimate.co.tz</p>
        </div>
        <div className="qrd-actions">
          <Action href={links.call} icon={HiOutlinePhone} label="Call" />
          <Action href={links.whatsapp} icon={HiOutlineChatBubbleLeftRight} label="WhatsApp" external />
          <Action href={links.email} icon={HiOutlineEnvelope} label="Email" />
          <Action href={createUrl} icon={HiOutlinePlus} label="Create quotation" primary />
        </div>
      </header>

      <section className="qrd-card">
        <h2 className="qrd-label">Customer</h2>
        <div className="qrd-customer">
          <span className="qrd-avatar" aria-hidden="true">{initials(customer.name)}</span>
          <div className="qrd-customer-text">
            <strong>{customer.name}</strong>
            {contact.length > 0 && (
              <span className="qrd-contact">
                {customer.phone && <a href={links.call || undefined}>{customer.phone}</a>}
                {customer.phone && customer.email && <span aria-hidden="true">&middot;</span>}
                {customer.email && <a href={links.email || undefined}>{customer.email}</a>}
              </span>
            )}
          </div>
        </div>
        {customer.notes && <p className="qrd-notes">{customer.notes}</p>}
      </section>

      <section className="qrd-card">
        <div className="qrd-card-head">
          <h2 className="qrd-label">Products</h2>
          <span className="qrd-count">
            {items.length} {items.length === 1 ? 'product' : 'products'} &middot; {quote.quantityTotal} units
          </span>
        </div>
        <ul className="qrd-items">
          {items.map((item, index) => (
            <li className="qrd-item" key={`${item.name}-${index}`}>
              {item.image ? (
                <img className="qrd-thumb" src={item.image} alt="" loading="lazy" />
              ) : (
                <span className="qrd-thumb qrd-thumb--empty" />
              )}
              <div className="qrd-item-text">
                <span className="qrd-item-name">{item.name}</span>
                <span className="qrd-item-qty">
                  {item.quantity} &times; {item.unitPrice || 'Price on request'}
                </span>
              </div>
              <span className="qrd-item-total">{item.lineTotal || '\u2014'}</span>
            </li>
          ))}
        </ul>
        <div className="qrd-total">
          <span>
            Estimated total <span className="qrd-hint">at website prices</span>
          </span>
          <strong>{quote.total || '\u2014'}</strong>
        </div>
      </section>
    </div>
  );
}
