import React, { useEffect, useRef, useState } from 'react';
import { HiOutlineArrowLeft, HiOutlineArrowPath, HiOutlineCheck, HiOutlineEye } from 'react-icons/hi2';
import './products-desk.css';
import './suppliers-desk.css';
import './shipments-desk.css';

const FLAG_BASE = 'https://flagcdn.com/w40/';

const CURRENCY_FLAGS = {
  USD: 'us',
  EUR: 'eu',
  GBP: 'gb',
  CNY: 'cn',
  JPY: 'jp',
  TZS: 'tz',
  AUD: 'au',
  CAD: 'ca',
  INR: 'in',
  AED: 'ae',
  HKD: 'hk',
};

function decodeHtmlEntities(value) {
  const raw = String(value ?? '');
  if (!raw || (raw.indexOf('&') === -1 && raw.indexOf('<') === -1)) return raw;
  if (typeof document !== 'undefined') {
    const el = document.createElement('textarea');
    let prev = raw;
    for (let i = 0; i < 3; i += 1) {
      el.innerHTML = prev;
      const next = el.value;
      if (next === prev) break;
      prev = next;
    }
    return prev;
  }
  return raw
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&apos;/g, "'");
}

function dateInputValue(value) {
  const s = String(value ?? '').trim();
  if (!s) return '';
  return s.length >= 10 ? s.slice(0, 10) : s;
}

function currencyFlagUrl(opt) {
  const direct = String(opt?.flag_url || '').trim();
  if (direct) return direct;
  const country = String(
    opt?.flag || CURRENCY_FLAGS[String(opt?.code || '').toUpperCase()] || 'un'
  ).toLowerCase();
  return `${FLAG_BASE}${country}.png`;
}

function ShipCurrencyPicker({ value, options, onChange, name = 'total_value_currency' }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const selected = options.find((o) => o.code === value) || options[0] || { code: value, label: value };
  const selectedFlag = currencyFlagUrl(selected);

  useEffect(() => {
    if (!open) return undefined;
    const onDoc = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div className={`ship-edit-currency-picker${open ? ' is-open' : ''}`} ref={ref}>
      <input type="hidden" name={name} value={value} />
      <button
        type="button"
        className="ship-edit-currency-trigger"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label="Invoice currency"
        onClick={() => setOpen((v) => !v)}
      >
        <img src={selectedFlag} alt="" className="ship-edit-currency-flag" width={28} height={20} />
        <span className="ship-edit-currency-label">
          <span className="code">{selected.code}</span>
          <span className="name">{selected.label || selected.code}</span>
        </span>
      </button>
      {open ? (
        <div className="ship-edit-currency-menu" role="listbox">
          {options.map((opt) => {
            const isSelected = opt.code === value;
            return (
              <button
                key={opt.code}
                type="button"
                role="option"
                aria-selected={isSelected}
                className={`ship-edit-currency-option${isSelected ? ' is-selected' : ''}`}
                onClick={() => {
                  onChange(opt.code);
                  setOpen(false);
                }}
              >
                <img
                  src={currencyFlagUrl(opt)}
                  alt=""
                  className="ship-edit-currency-flag"
                  width={28}
                  height={20}
                  loading="lazy"
                />
                <span className="code">{opt.code}</span>
                <span className="name">{opt.label || opt.code}</span>
              </button>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}

const DEFAULT_STATUS_OPTS = [
  { value: 'pending', label: 'Pending' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'shipped', label: 'Shipped' },
  { value: 'in_transit', label: 'In transit' },
  { value: 'arrived_at_port', label: 'Arrived at port' },
  { value: 'in_customs', label: 'In customs' },
  { value: 'ready_for_pickup', label: 'Ready for pickup' },
  { value: 'out_for_delivery', label: 'Out for delivery' },
  { value: 'delivered', label: 'Delivered' },
  { value: 'delayed', label: 'Delayed' },
  { value: 'cancelled', label: 'Cancelled' },
];

const DEFAULT_CURRENCIES = [
  { code: 'USD', label: 'USD ($)', flag: 'us' },
  { code: 'EUR', label: 'EUR (EUR)', flag: 'eu' },
  { code: 'GBP', label: 'GBP (GBP)', flag: 'gb' },
  { code: 'CNY', label: 'CNY (CNY)', flag: 'cn' },
  { code: 'JPY', label: 'JPY (JPY)', flag: 'jp' },
  { code: 'TZS', label: 'TZS', flag: 'tz' },
  { code: 'AUD', label: 'AUD (A$)', flag: 'au' },
  { code: 'CAD', label: 'CAD (C$)', flag: 'ca' },
  { code: 'INR', label: 'INR (INR)', flag: 'in' },
  { code: 'AED', label: 'AED', flag: 'ae' },
  { code: 'HKD', label: 'HKD (HK$)', flag: 'hk' },
];

export default function ShipmentEdit({ data }) {
  const {
    indexUrl = 'index.php',
    viewUrl = 'view.php',
    poViewUrl = '../purchases/view_po.php',
    formAction = 'edit.php',
    error = '',
    shipment = {},
    suppliers = [],
    shippers = [],
    currencies = [],
    statusOptions = DEFAULT_STATUS_OPTS,
  } = data;

  const [activeSection, setActiveSection] = useState('basic');
  const [saving, setSaving] = useState(false);
  const [currencyCode, setCurrencyCode] = useState(shipment.total_value_currency || 'USD');

  const invoiceNumber = decodeHtmlEntities(shipment.invoice_number || '');
  const trackingNumber = decodeHtmlEntities(shipment.tracking_number || '');
  const contactNumber = decodeHtmlEntities(shipment.contact_number || '');
  const description = decodeHtmlEntities(shipment.description || '');
  const supplierId = shipment.supplier_id != null ? String(shipment.supplier_id) : '';
  const shipperId = shipment.shipper_id != null && shipment.shipper_id !== '' ? String(shipment.shipper_id) : '';
  const status = shipment.status || 'pending';
  const estCost = shipment.estimated_clearance_cost != null ? String(shipment.estimated_clearance_cost) : '0.00';
  const packagesCount = shipment.packages_count != null ? String(shipment.packages_count) : '1';
  const cbm = shipment.cbm != null ? String(shipment.cbm) : '0.000';
  const totalValue = shipment.total_value != null ? String(shipment.total_value) : '0.00';
  const shipmentDate = dateInputValue(shipment.shipment_date);
  const etd = dateInputValue(shipment.etd);
  const eta = dateInputValue(shipment.eta);

  const currencyOptions = (Array.isArray(currencies) && currencies.length ? currencies : DEFAULT_CURRENCIES).map(
    (c) => ({
      code: String(c.code || '').toUpperCase(),
      label: c.label || c.code,
      flag: c.flag || CURRENCY_FLAGS[String(c.code || '').toUpperCase()] || 'un',
      flag_url: c.flag_url || '',
    })
  );

  let statusOpts = Array.isArray(statusOptions) && statusOptions.length ? statusOptions : DEFAULT_STATUS_OPTS;
  if (status && !statusOpts.some((o) => o.value === status)) {
    statusOpts = [
      { value: status, label: String(status).replace(/_/g, ' ') },
      ...statusOpts,
    ];
  }

  const linkedPoId = shipment.stocks_po_id ? Number(shipment.stocks_po_id) : null;
  const linkedPoNumber = shipment.linked_po_number || (linkedPoId ? `#${linkedPoId}` : '');

  useEffect(() => {
    const sections = [
      { id: 'basic', el: document.getElementById('ship-edit-basic') },
      { id: 'shipping', el: document.getElementById('ship-edit-shipping') },
      { id: 'timeline', el: document.getElementById('ship-edit-timeline') },
      { id: 'cargo', el: document.getElementById('ship-edit-cargo') },
    ];
    const onScroll = () => {
      const y = window.scrollY + 140;
      let current = 'basic';
      sections.forEach((s) => {
        if (s.el && s.el.offsetTop <= y) current = s.id;
      });
      setActiveSection(current);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  return (
    <div className="prod-desk-page supplier-edit ship-edit">
      <div className="supplier-edit-top">
        <div className="supplier-edit-top-lead">
          <div className="supplier-edit-top-meta">
            <p className="supplier-edit-name">Edit shipment</p>
            <p className="supplier-edit-sub">
              {invoiceNumber || 'No invoice yet'}
              {linkedPoId ? (
                <>
                  {' � '}
                  Linked stock PO{' '}
                  <a className="ship-desk-cell-link" href={`${poViewUrl}?id=${linkedPoId}`}>
                    {linkedPoNumber}
                  </a>
                </>
              ) : null}
            </p>
          </div>
        </div>
        <div className="supplier-edit-top-actions">
          <a href={indexUrl} className="prod-desk-btn prod-desk-btn--ghost">
            <HiOutlineArrowLeft size={16} aria-hidden="true" />
            Shipments
          </a>
          <a href={`${viewUrl}?id=${shipment.id || ''}`} className="prod-desk-btn prod-desk-btn--ghost">
            <HiOutlineEye size={16} aria-hidden="true" />
            View
          </a>
        </div>
      </div>

      {error ? <div className="supplier-edit-error">{error}</div> : null}

      <form
        method="post"
        action={formAction}
        className="supplier-edit-layout"
        onSubmit={() => setSaving(true)}
      >
        <aside className="supplier-edit-nav" aria-label="Form sections">
          <a href="#ship-edit-basic" className={activeSection === 'basic' ? 'is-active' : undefined}>
            Basic
          </a>
          <a href="#ship-edit-shipping" className={activeSection === 'shipping' ? 'is-active' : undefined}>
            Shipping
          </a>
          <a href="#ship-edit-timeline" className={activeSection === 'timeline' ? 'is-active' : undefined}>
            Timeline
          </a>
          <a href="#ship-edit-cargo" className={activeSection === 'cargo' ? 'is-active' : undefined}>
            Cargo
          </a>
        </aside>

        <div className="supplier-edit-main">
          <section className="supplier-edit-section" id="ship-edit-basic">
            <header className="supplier-edit-section-head">
              <h2>Basic information</h2>
              <p>Supplier, invoice, and description for this shipment.</p>
            </header>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="supplier_id">
                Supplier <span className="req">*</span>
              </label>
              <div>
                <select
                  id="supplier_id"
                  name="supplier_id"
                  className="supplier-edit-input"
                  required
                  defaultValue={supplierId}
                >
                  <option value="">Select supplier</option>
                  {suppliers.map((s) => (
                    <option key={s.id} value={String(s.id)}>
                      {s.name}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="invoice_number">
                Invoice number <span className="req">*</span>
              </label>
              <div>
                <input
                  id="invoice_number"
                  name="invoice_number"
                  type="text"
                  className="supplier-edit-input"
                  required
                  defaultValue={invoiceNumber}
                  placeholder="e.g. INV-2026-001"
                  autoComplete="off"
                />
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="contact_number">
                Contact number
              </label>
              <div>
                <input
                  id="contact_number"
                  name="contact_number"
                  type="text"
                  className="supplier-edit-input"
                  defaultValue={contactNumber}
                  placeholder="e.g. 0086123456789"
                  autoComplete="off"
                />
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="description">
                Description
              </label>
              <div>
                <textarea
                  id="description"
                  name="description"
                  rows={3}
                  className="supplier-edit-input supplier-edit-textarea"
                  defaultValue={description}
                  placeholder="e.g. product summary for customs"
                />
              </div>
            </div>
          </section>

          <section className="supplier-edit-section" id="ship-edit-shipping">
            <header className="supplier-edit-section-head">
              <h2>Shipping &amp; status</h2>
              <p>Forwarder, tracking, clearance estimate, and current status.</p>
            </header>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="shipper_id">
                Shipper / forwarder
              </label>
              <div>
                <select
                  id="shipper_id"
                  name="shipper_id"
                  className="supplier-edit-input"
                  defaultValue={shipperId}
                >
                  <option value="">Select shipper</option>
                  {shippers.map((s) => (
                    <option key={s.id} value={String(s.id)}>
                      {s.name}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="status">
                Status
              </label>
              <div>
                <select id="status" name="status" className="supplier-edit-input" defaultValue={status}>
                  {statusOpts.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="estimated_clearance_cost">
                Est. clearance cost
              </label>
              <div className="ship-edit-money">
                <span className="ship-edit-money-prefix" aria-hidden="true">
                  $
                </span>
                <input
                  id="estimated_clearance_cost"
                  name="estimated_clearance_cost"
                  type="number"
                  step="0.01"
                  className="supplier-edit-input"
                  defaultValue={estCost}
                />
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="tracking_number">
                Tracking number
              </label>
              <div>
                <input
                  id="tracking_number"
                  name="tracking_number"
                  type="text"
                  className="supplier-edit-input"
                  defaultValue={trackingNumber}
                  autoComplete="off"
                />
              </div>
            </div>
          </section>

          <section className="supplier-edit-section" id="ship-edit-timeline">
            <header className="supplier-edit-section-head">
              <h2>Timeline</h2>
              <p>Shipment date, departure (ETD), and arrival (ETA).</p>
            </header>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="shipment_date">
                Shipment date
              </label>
              <div>
                <input
                  id="shipment_date"
                  name="shipment_date"
                  type="date"
                  className="supplier-edit-input"
                  defaultValue={shipmentDate}
                />
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="etd">
                ETD (departure)
              </label>
              <div>
                <input id="etd" name="etd" type="date" className="supplier-edit-input" defaultValue={etd} />
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="eta">
                ETA (arrival)
              </label>
              <div>
                <input id="eta" name="eta" type="date" className="supplier-edit-input" defaultValue={eta} />
              </div>
            </div>
          </section>

          <section className="supplier-edit-section" id="ship-edit-cargo">
            <header className="supplier-edit-section-head">
              <h2>Cargo</h2>
              <p>Packages, volume, and invoice value.</p>
            </header>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="packages_count">
                Total packages
              </label>
              <div>
                <input
                  id="packages_count"
                  name="packages_count"
                  type="number"
                  min="1"
                  className="supplier-edit-input"
                  defaultValue={packagesCount}
                />
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="cbm">
                Total CBM (m3)
              </label>
              <div>
                <input
                  id="cbm"
                  name="cbm"
                  type="number"
                  step="0.001"
                  className="supplier-edit-input"
                  defaultValue={cbm}
                />
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="total_value">
                Total invoice value
              </label>
              <div className="ship-edit-value-row">
                <ShipCurrencyPicker
                  value={currencyCode}
                  options={currencyOptions}
                  onChange={setCurrencyCode}
                />
                <input
                  id="total_value"
                  name="total_value"
                  type="number"
                  step="0.01"
                  className="supplier-edit-input"
                  defaultValue={totalValue}
                />
              </div>
            </div>
          </section>

          <div className="supplier-edit-actions">
            <button
              type="submit"
              className={`prod-desk-btn ship-edit-btn-primary${saving ? ' is-saving' : ''}`}
              disabled={saving}
            >
              {saving ? (
                <>
                  <HiOutlineArrowPath size={16} className="supplier-edit-spin" aria-hidden="true" />
                  Saving...
                </>
              ) : (
                <>
                  <HiOutlineCheck size={16} aria-hidden="true" />
                  Update shipment
                </>
              )}
            </button>
            <a href={`${viewUrl}?id=${shipment.id || ''}`} className="prod-desk-btn prod-desk-btn--ghost">
              Cancel
            </a>
          </div>
        </div>
      </form>
    </div>
  );
}
