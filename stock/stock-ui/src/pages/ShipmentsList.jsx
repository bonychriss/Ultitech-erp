import React, { useEffect, useMemo, useState } from 'react';
import {
  HiOutlineArrowDownTray,
  HiOutlineCube,
  HiOutlineMagnifyingGlass,
  HiOutlinePencilSquare,
  HiOutlinePhone,
  HiOutlinePlus,
  HiOutlineShoppingCart,
  HiOutlineTruck,
  HiOutlineXMark,
} from 'react-icons/hi2';
import './products-desk.css';
import './shipments-desk.css';

const STATUS_META = {
  pending: { label: 'Pending', className: 'ship-desk-status--pending' },
  shipped: { label: 'Shipped', className: 'ship-desk-status--shipped' },
  in_transit: { label: 'In transit', className: 'ship-desk-status--in_transit' },
  arrived_at_port: { label: 'Port arrival', className: 'ship-desk-status--arrived_at_port' },
  in_customs: { label: 'In customs', className: 'ship-desk-status--in_customs' },
  delivered: { label: 'Delivered', className: 'ship-desk-status--delivered' },
  delayed: { label: 'Delayed', className: 'ship-desk-status--delayed' },
  cancelled: { label: 'Cancelled', className: 'ship-desk-status--cancelled' },
};

function normalizeSearchQuery(q) {
  return String(q || '')
    .trim()
    .replace(/\s+/g, ' ');
}

function shipmentMatchesQuery(q, ship) {
  const tokens = normalizeSearchQuery(q)
    .toLowerCase()
    .split(' ')
    .filter(Boolean);
  if (!tokens.length) return true;
  const hay = [
    ship.supplier_name,
    ship.linked_po_number,
    ship.contact_number,
    ship.invoice_number,
    ship.tracking_number,
    ship.description,
    ship.shipper_real_name,
    ship.shipper_name,
    ship.shipper,
    ship.status,
  ]
    .map((v) => String(v || '').toLowerCase())
    .join(' ');
  return tokens.every((t) => hay.includes(t));
}

function formatDate(d) {
  if (!d || !String(d).trim()) return '—';
  const t = Date.parse(d);
  if (Number.isNaN(t)) return '—';
  return new Date(t).toISOString().slice(0, 10);
}

function formatMoney(value, prefix = '$') {
  const n = Number(value);
  if (Number.isNaN(n)) return '—';
  return `${prefix}${n.toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function phoneDigits(s) {
  return String(s || '').replace(/\D/g, '');
}

function StatusBadge({ status }) {
  const key = String(status || '').toLowerCase();
  const meta = STATUS_META[key] || {
    label: key ? key.replace(/_/g, ' ') : '—',
    className: 'ship-desk-status--default',
  };
  return <span className={`ship-desk-status ${meta.className}`}>{meta.label}</span>;
}

export default function ShipmentsList({ data }) {
  const {
    shipments: initialShipments = [],
    search: initialSearch = '',
    notice = '',
    createSuccess = null,
    urls = {},
  } = data;

  const createUrl = urls.create || 'create.php';
  const importUrl = urls.import || 'import.php';
  const shippersUrl = urls.shippers || '../shippers/index.php';
  const purchasesUrl = urls.purchases || '../purchases/index.php';
  const viewUrl = urls.view || 'view.php';
  const editUrl = urls.edit || 'edit.php';
  const poViewUrl = urls.poView || '../purchases/view_po.php';

  const [shipments] = useState(initialShipments);
  const [search, setSearch] = useState(initialSearch);
  const [booting, setBooting] = useState(true);
  const [noticeText, setNoticeText] = useState(notice || '');

  useEffect(() => {
    const t = window.setTimeout(() => setBooting(false), 180);
    return () => window.clearTimeout(t);
  }, []);

  useEffect(() => {
    if (!createSuccess || !window.Swal) return;
    window.Swal.fire({
      title: createSuccess.title || 'Success',
      text: createSuccess.message || '',
      icon: createSuccess.variant === 'warning' ? 'warning' : 'success',
      confirmButtonColor: '#2563eb',
      confirmButtonText: 'OK',
    });
  }, [createSuccess]);

  const filtered = useMemo(
    () => shipments.filter((s) => shipmentMatchesQuery(search, s)),
    [shipments, search]
  );

  if (booting) {
    return (
      <div className="prod-desk-page" aria-busy="true">
        <div className="prod-desk-page-header">
          <div className="prod-desk-page-header-search">
            <div className="prod-desk-search-field">
              <span className="prod-desk-bone prod-desk-bone--name" style={{ height: 36, borderRadius: 9999 }} />
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="prod-desk-page">
      {noticeText ? (
        <div className="ship-desk-notice" role="status">
          <span>{noticeText}</span>
          <button
            type="button"
            className="prod-desk-icon-btn"
            style={{ marginLeft: '0.75rem' }}
            aria-label="Dismiss notice"
            onClick={() => setNoticeText('')}
          >
            <HiOutlineXMark size={16} />
          </button>
        </div>
      ) : null}

      <div className="ship-desk-toolbar">
        <a href={createUrl} className="ship-desk-link ship-desk-link--primary">
          <HiOutlinePlus size={16} aria-hidden="true" /> New shipment
        </a>
        <a href={importUrl} className="ship-desk-link">
          <HiOutlineArrowDownTray size={15} aria-hidden="true" /> Import
        </a>
        <a href={shippersUrl} className="ship-desk-link">
          <HiOutlineTruck size={15} aria-hidden="true" /> Shippers
        </a>
        <span className="ship-desk-toolbar-spacer" aria-hidden="true" />
        <a href={purchasesUrl} className="ship-desk-link">
          <HiOutlineShoppingCart size={15} aria-hidden="true" /> Purchases
        </a>
      </div>

      <div className="prod-desk-page-header">
        <div className="prod-desk-page-header-search">
          <div className="prod-desk-search-field">
            <HiOutlineMagnifyingGlass className="prod-desk-search-icon" aria-hidden="true" />
            <input
              type="search"
              className="prod-desk-search-input"
              placeholder="Search invoice, tracking, supplier..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              aria-label="Search shipments"
            />
          </div>
        </div>
      </div>

      <section className="prod-desk-results">
        <div className="prod-desk-results-head">
          <span className="prod-desk-results-count">
            {filtered.length} {filtered.length === 1 ? 'shipment' : 'shipments'}
            {search.trim() ? ' · Filtered' : ''}
          </span>
        </div>

        {filtered.length === 0 ? (
          <div className="ship-desk-empty" role="status">
            <div className="ship-desk-empty-icon" aria-hidden="true">
              <span className="ship-desk-empty-ring" />
              {shipments.length === 0 ? <HiOutlineTruck size={34} /> : <HiOutlineMagnifyingGlass size={32} />}
            </div>
            <p className="ship-desk-empty-title">No shipments found</p>
            <p className="ship-desk-empty-hint">
              {shipments.length === 0
                ? 'Create a shipment to start tracking freight.'
                : 'Try a different search.'}
            </p>
            {shipments.length === 0 ? (
              <a href={createUrl} className="prod-desk-btn prod-desk-btn-primary ship-desk-btn ship-desk-empty-cta">
                <HiOutlinePlus size={16} aria-hidden="true" /> New shipment
              </a>
            ) : (
              <button
                type="button"
                className="prod-desk-btn prod-desk-btn-secondary ship-desk-btn ship-desk-empty-cta"
                onClick={() => setSearch('')}
              >
                Clear search
              </button>
            )}
          </div>
        ) : (
          <div className="prod-desk-table-wrap ship-desk-table-wrap">
            <table className="prod-desk-table ship-desk-table">
              <thead>
                <tr>
                  <th className="ship-col-supplier">Supplier</th>
                  <th className="ship-col-po">Stock PO</th>
                  <th className="ship-col-contact">Contact</th>
                  <th className="ship-col-inv">Invoice #</th>
                  <th className="ship-col-track">Track</th>
                  <th className="ship-col-pkgs is-num">Pkgs</th>
                  <th className="ship-col-cbm is-num">CBM</th>
                  <th className="ship-col-value is-num">Value</th>
                  <th className="ship-col-desc">Desc</th>
                  <th className="ship-col-date">Ship date</th>
                  <th className="ship-col-shipper">Shipper</th>
                  <th className="ship-col-ecc is-num">ECC</th>
                  <th className="ship-col-etd">ETD</th>
                  <th className="ship-col-eta">ETA</th>
                  <th className="ship-col-status">Status</th>
                  <th className="ship-col-actions is-actions">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((ship) => {
                  const canEdit = ship.status !== 'delivered' && ship.status !== 'cancelled';
                  const prefix = ship.value_prefix || '$';
                  const shipperLabel = ship.shipper_real_name || ship.shipper_name || ship.shipper || '—';
                  return (
                    <tr
                      key={ship.id}
                      onClick={() => {
                        window.location.href = `${viewUrl}?id=${ship.id}`;
                      }}
                    >
                      <td className="ship-col-supplier">
                        <div className="ship-desk-cell-strong" title={ship.supplier_name || ''}>
                          {ship.supplier_name || '—'}
                        </div>
                      </td>
                      <td className="ship-col-po" onClick={(e) => e.stopPropagation()}>
                        {ship.stocks_po_id ? (
                          <a
                            className="ship-desk-cell-link"
                            href={`${poViewUrl}?id=${ship.stocks_po_id}`}
                            title={ship.linked_po_number || ''}
                          >
                            {ship.linked_po_number || `#${ship.stocks_po_id}`}
                          </a>
                        ) : (
                          <span className="prod-desk-muted">—</span>
                        )}
                      </td>
                      <td className="ship-col-contact" onClick={(e) => e.stopPropagation()}>
                        {ship.contact_number ? (
                          <div className="ship-desk-contact">
                            <span className="ship-desk-cell-clip" title={ship.contact_number}>
                              {ship.contact_number}
                            </span>
                            <div className="ship-desk-contact-links">
                              <a href={`tel:${String(ship.contact_number).replace(/\s+/g, '')}`} title="Call">
                                <HiOutlinePhone size={12} aria-hidden="true" />
                              </a>
                              <a
                                href={`https://wa.me/${phoneDigits(ship.contact_number)}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                title="WhatsApp"
                              >
                                WA
                              </a>
                            </div>
                          </div>
                        ) : (
                          <span className="prod-desk-muted">—</span>
                        )}
                      </td>
                      <td className="ship-col-inv">
                        <span className="ship-desk-cell-clip" title={ship.invoice_number || ''}>
                          {ship.invoice_number || '—'}
                        </span>
                      </td>
                      <td className="ship-col-track">
                        <span className="ship-desk-cell-clip" title={ship.tracking_number || ''}>
                          {ship.tracking_number || 'N/A'}
                        </span>
                      </td>
                      <td className="ship-col-pkgs is-num">{ship.packages_count ?? 0}</td>
                      <td className="ship-col-cbm is-num">
                        {ship.cbm != null ? Number(ship.cbm).toFixed(3) : '—'}
                      </td>
                      <td className="ship-col-value is-num ship-desk-cell-strong">
                        {formatMoney(ship.total_value, prefix)}
                      </td>
                      <td className="ship-col-desc">
                        <span className="ship-desk-cell-clip" title={ship.description || ''}>
                          {ship.description || '—'}
                        </span>
                      </td>
                      <td className="ship-col-date">{formatDate(ship.shipment_date)}</td>
                      <td className="ship-col-shipper">
                        <span className="ship-desk-cell-clip" title={shipperLabel}>
                          {shipperLabel}
                        </span>
                      </td>
                      <td className="ship-col-ecc is-num">{formatMoney(ship.estimated_clearance_cost, '$')}</td>
                      <td className="ship-col-etd">{formatDate(ship.etd)}</td>
                      <td className="ship-col-eta">{formatDate(ship.eta)}</td>
                      <td className="ship-col-status">
                        <StatusBadge status={ship.status} />
                      </td>
                      <td className="ship-col-actions is-actions" onClick={(e) => e.stopPropagation()}>
                        <div className="prod-desk-actions">
                          {canEdit ? (
                            <a
                              href={`${editUrl}?id=${ship.id}`}
                              className="prod-desk-icon-btn prod-desk-icon-btn--edit"
                              title="Edit"
                              aria-label="Edit shipment"
                            >
                              <HiOutlinePencilSquare size={16} aria-hidden="true" />
                            </a>
                          ) : null}
                          <a
                            href={`${viewUrl}?id=${ship.id}&tab=packages`}
                            className="prod-desk-icon-btn"
                            title="Packages"
                            aria-label="View packages"
                          >
                            <HiOutlineCube size={16} aria-hidden="true" />
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
    </div>
  );
}
