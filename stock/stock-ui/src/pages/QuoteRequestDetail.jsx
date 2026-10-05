import React, { useEffect, useState } from 'react';
import {
  HiOutlineArrowLeft,
  HiOutlineChatBubbleLeftRight,
  HiOutlineEnvelope,
  HiOutlinePhone,
  HiOutlinePlus,
  HiOutlineTrash,
  HiTrash,
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

function DeleteDialog({ quote, customerName, api, csrf, backUrl, onClose }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    const onKey = (event) => {
      if (event.key === 'Escape' && !busy) onClose();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [busy, onClose]);

  const remove = async () => {
    setBusy(true);
    setError('');
    try {
      const body = new URLSearchParams({ action: 'delete', quote_number: quote, csrf_token: csrf });
      const res = await fetch(api, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body,
      });
      const json = await res.json().catch(() => null);
      if (!json || !json.success) {
        throw new Error((json && json.message) || 'The request could not be deleted.');
      }
      window.location.href = `${backUrl}${backUrl.includes('?') ? '&' : '?'}deleted=1`;
    } catch (err) {
      setError(err.message || 'The request could not be deleted.');
      setBusy(false);
    }
  };

  return (
    <div
      className="qrd-modal"
      onClick={(event) => {
        if (event.target === event.currentTarget && !busy) onClose();
      }}
    >
      <div className="qrd-modal-box" role="dialog" aria-modal="true" aria-labelledby="qrd-delete-title">
        <div className="qrd-modal-body">
          <div className="qrd-modal-main">
            <span className="qrd-modal-icon">
              <HiTrash aria-hidden="true" />
            </span>
            <div className="qrd-modal-copy">
              <h2 id="qrd-delete-title">Delete quote request?</h2>
              <p>This request will be removed from the list:</p>
              <ul className="qrd-modal-items">
                <li>
                  {quote} {'\u00b7'} {customerName}
                </li>
              </ul>
            </div>
          </div>
          {error && <p className="qrd-modal-error">{error}</p>}
        </div>
        <div className="qrd-modal-actions">
          <button type="button" className="qrd-btn" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="button" className="qrd-btn qrd-btn--danger" onClick={remove} disabled={busy} autoFocus>
            {busy ? 'Deleting...' : 'Delete request'}
          </button>
        </div>
      </div>
    </div>
  );
}

export default function QuoteRequestDetail({ data }) {
  const { found = false, backUrl = '', createUrl = '', deleteApi = '', csrf = '', quote = null } = data;
  const [confirming, setConfirming] = useState(false);

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
          {deleteApi && (
            <button type="button" className="qrd-action qrd-action--danger" onClick={() => setConfirming(true)}>
              <HiOutlineTrash aria-hidden="true" />
              <span>Delete</span>
            </button>
          )}
          <Action href={createUrl} icon={HiOutlinePlus} label="Create quotation" primary />
        </div>
      </header>

      {confirming && (
        <DeleteDialog
          quote={quote.number}
          customerName={customer.name}
          api={deleteApi}
          csrf={csrf}
          backUrl={backUrl}
          onClose={() => setConfirming(false)}
        />
      )}

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
