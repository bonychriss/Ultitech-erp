import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  HiOutlineEye,
  HiOutlineInbox,
  HiOutlineMagnifyingGlass,
  HiOutlinePencilSquare,
  HiOutlinePlus,
  HiOutlineTrash,
} from 'react-icons/hi2';
import './products-desk.css';
import './suppliers-desk.css';

function typeLabel(type) {
  if (type === 'vehicle' || type === 'truck') return 'Truck';
  if (type === 'spare_part') return 'Spare Part';
  return 'General';
}

function typeClass(type) {
  if (type === 'vehicle' || type === 'truck') return 'supplier-desk-type--truck';
  if (type === 'spare_part') return 'supplier-desk-type--spare';
  return 'supplier-desk-type--general';
}

function cssStringToStyle(css) {
  if (!css || typeof css !== 'string') return undefined;
  const out = {};
  css.split(';').forEach((part) => {
    const idx = part.indexOf(':');
    if (idx < 1) return;
    const rawKey = part.slice(0, idx).trim();
    const value = part.slice(idx + 1).trim();
    if (!rawKey || !value) return;
    const key = rawKey.replace(/-([a-z])/gi, (_, c) => String(c).toUpperCase());
    out[key] = value;
  });
  return Object.keys(out).length ? out : undefined;
}

function normalizeSearchQuery(q) {
  return String(q || '')
    .trim()
    .replace(/\s+/g, ' ');
}

function supplierMatchesQuery(q, supplier) {
  const tokens = normalizeSearchQuery(q)
    .toLowerCase()
    .split(' ')
    .filter(Boolean);
  if (tokens.length === 0) return true;
  const hay = [
    supplier.name,
    supplier.supplier_code,
    supplier.contact_person,
    supplier.phone,
    supplier.email,
    supplier.address,
    supplier.location,
    typeLabel(supplier.detected_type),
  ]
    .map((v) => String(v || '').toLowerCase())
    .join(' ');
  return tokens.every((t) => hay.includes(t));
}

function cleanSuccessUrlParams() {
  try {
    const url = new URL(window.location.href);
    url.searchParams.delete('msg');
    url.searchParams.delete('created_id');
    const qs = url.searchParams.toString();
    window.history.replaceState({}, '', url.pathname + (qs ? `?${qs}` : ''));
  } catch {
    // ignore
  }
}

export default function SuppliersList({ data }) {
  const {
    isRoadmaster = false,
    suppliers: initialSuppliers = [],
    typeFilter: initialType = 'all',
    search: initialSearch = '',
    addUrl = 'add.php',
    viewUrl = 'view.php',
    editUrl = 'edit.php',
    deleteUrl = 'delete.php',
    toast = '',
    createdId = 0,
  } = data;

  const [suppliers] = useState(initialSuppliers);
  const [search, setSearch] = useState(initialSearch);
  const [typeFilter, setTypeFilter] = useState(
    isRoadmaster && ['all', 'vehicle', 'spare_part'].includes(initialType) ? initialType : 'all'
  );
  const [booting, setBooting] = useState(true);
  const highlightRef = useRef(null);

  useEffect(() => {
    const timer = window.setTimeout(() => setBooting(false), 220);
    return () => window.clearTimeout(timer);
  }, []);

  useEffect(() => {
    if (!toast) return;
    const message = String(toast);
    const lottie = window.StockSupplierSuccessLottie;
    if (lottie?.isMobile?.() && lottie.show?.(message)) {
      cleanSuccessUrlParams();
      return;
    }
    if (lottie?.show?.(message)) {
      cleanSuccessUrlParams();
      return;
    }
    if (window.Swal) {
      window.Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: message,
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true,
      });
    }
    cleanSuccessUrlParams();
  }, [toast]);

  useEffect(() => {
    if (booting || !createdId) return undefined;
    const t = window.setTimeout(() => {
      highlightRef.current?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
    }, 80);
    return () => window.clearTimeout(t);
  }, [booting, createdId]);

  const filtered = useMemo(() => {
    return suppliers.filter((s) => {
      if (isRoadmaster && typeFilter !== 'all' && s.detected_type !== typeFilter) return false;
      return supplierMatchesQuery(search, s);
    });
  }, [suppliers, search, typeFilter, isRoadmaster]);

  const startAdd = () => {
    if (!isRoadmaster) {
      window.location.href = `${addUrl}?type=general`;
      return;
    }
    if (!window.Swal) {
      window.location.href = `${addUrl}?type=spare_part`;
      return;
    }
    window.Swal.fire({
      title: 'Register Supplier',
      text: 'Choose the department for this new partner',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Spare Parts',
      cancelButtonText: 'Trucks / Vehicles',
      confirmButtonColor: '#10b981',
      cancelButtonColor: '#3b82f6',
      reverseButtons: true,
    }).then((result) => {
      if (result.isConfirmed) {
        window.location.href = `${addUrl}?type=spare_part`;
      } else if (result.dismiss === window.Swal.DismissReason.cancel) {
        window.location.href = `${addUrl}?type=vehicle`;
      }
    });
  };

  const confirmDelete = (id, name) => {
    const go = () => {
      window.location.href = `${deleteUrl}?id=${id}`;
    };
    if (window.Swal) {
      window.Swal.fire({
        title: 'Remove Partner?',
        text: name
          ? `"${name}" will be permanently removed from the registry.`
          : 'This supplier will be permanently removed from the registry.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, remove',
      }).then((r) => {
        if (r.isConfirmed) go();
      });
      return;
    }
    if (window.confirm(`Delete "${name || 'supplier'}"?`)) go();
  };

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
      <div className="prod-desk-page-header">
        <div className="prod-desk-page-header-search">
          <div className="prod-desk-search-field">
            <HiOutlineMagnifyingGlass className="prod-desk-search-icon" aria-hidden="true" />
            <input
              type="search"
              className="prod-desk-search-input"
              placeholder="Search suppliers..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              aria-label="Search suppliers"
            />
          </div>
        </div>
        <div className="prod-desk-page-header-actions">
          <button
            type="button"
            className="prod-desk-btn prod-desk-btn-primary supplier-desk-btn"
            onClick={startAdd}
          >
            <HiOutlinePlus size={16} aria-hidden="true" />
            <span className="prod-desk-btn-label-desktop">Add supplier</span>
            <span className="prod-desk-btn-label-mobile">New</span>
          </button>
        </div>
      </div>

      {isRoadmaster ? (
        <nav className="supplier-desk-tabs" aria-label="Supplier types" style={{ marginBottom: '0.85rem' }}>
          {[
            { id: 'all', label: 'All' },
            { id: 'vehicle', label: 'Trucks' },
            { id: 'spare_part', label: 'Spare Parts' },
          ].map((tab) => (
            <button
              key={tab.id}
              type="button"
              className={`supplier-desk-tab${typeFilter === tab.id ? ' is-active' : ''}`}
              onClick={() => setTypeFilter(tab.id)}
            >
              {tab.label}
            </button>
          ))}
        </nav>
      ) : null}

      <section className="prod-desk-results">
        <div className="prod-desk-results-head">
          <span className="prod-desk-results-count">
            {filtered.length} {filtered.length === 1 ? 'supplier' : 'suppliers'}
          </span>
        </div>

        {filtered.length === 0 ? (
          <div className="supplier-desk-empty" role="status">
            <div className="supplier-desk-empty-icon" aria-hidden="true">
              <span className="supplier-desk-empty-ring" />
              <span className="supplier-desk-empty-ring supplier-desk-empty-ring--delay" />
              {suppliers.length === 0 ? (
                <HiOutlineInbox size={36} />
              ) : (
                <HiOutlineMagnifyingGlass size={34} />
              )}
            </div>
            <p className="supplier-desk-empty-title">No suppliers found</p>
            <p className="supplier-desk-empty-hint">
              {suppliers.length === 0
                ? 'Add your first supplier to get started.'
                : 'Try a different search or filter.'}
            </p>
            {suppliers.length === 0 ? (
              <button
                type="button"
                className="prod-desk-btn prod-desk-btn-primary supplier-desk-btn supplier-desk-empty-cta"
                onClick={startAdd}
              >
                <HiOutlinePlus size={16} aria-hidden="true" /> Add supplier
              </button>
            ) : search.trim() ? (
              <button
                type="button"
                className="prod-desk-btn prod-desk-btn-secondary supplier-desk-btn supplier-desk-empty-cta"
                onClick={() => setSearch('')}
              >
                Clear search
              </button>
            ) : null}
          </div>
        ) : (
          <div className="prod-desk-table-wrap">
            <table className="prod-desk-table">
              <thead>
                <tr>
                  <th>Supplier</th>
                  {isRoadmaster ? <th>Type</th> : null}
                  <th>Contact</th>
                  <th>Email</th>
                  <th style={{ textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((s) => {
                  const isCreated = Number(createdId) > 0 && Number(s.id) === Number(createdId);
                  return (
                    <tr
                      key={s.id}
                      ref={isCreated ? highlightRef : null}
                      className={`supplier-desk-row${isCreated ? ' is-highlight' : ''}`}
                      data-id={s.id}
                    >
                      <td>
                        <div className="supplier-desk-cell-main">
                          <span
                            className="supplier-desk-avatar"
                            style={cssStringToStyle(s.avatar_style)}
                            title={s.name}
                            aria-hidden="true"
                          >
                            {s.initials || 'SU'}
                          </span>
                          <div className="supplier-desk-meta">
                            <a className="supplier-desk-name" href={`${viewUrl}?id=${s.id}`}>
                              {s.name}
                            </a>
                            {s.supplier_code ? (
                              <div className="supplier-desk-code">CODE: {s.supplier_code}</div>
                            ) : null}
                            {s.location_line ? (
                              <div className="supplier-desk-location">{s.location_line}</div>
                            ) : null}
                          </div>
                        </div>
                      </td>
                      {isRoadmaster ? (
                        <td>
                          <span className={`supplier-desk-type ${typeClass(s.detected_type)}`}>
                            {typeLabel(s.detected_type)}
                          </span>
                        </td>
                      ) : null}
                      <td>
                        <div className="supplier-desk-contact">{s.contact_person || '—'}</div>
                        <div className="supplier-desk-phone">{s.phone || 'No phone'}</div>
                      </td>
                      <td>
                        <div className="supplier-desk-email" title={s.email || ''}>
                          {s.email || '—'}
                        </div>
                      </td>
                      <td style={{ textAlign: 'right' }}>
                        <div className="prod-desk-actions">
                          <a
                            href={`${viewUrl}?id=${s.id}`}
                            className="prod-desk-icon-btn prod-desk-icon-btn--view"
                            title="View"
                            aria-label={`View ${s.name}`}
                          >
                            <HiOutlineEye size={16} aria-hidden="true" />
                          </a>
                          <a
                            href={`${editUrl}?id=${s.id}`}
                            className="prod-desk-icon-btn prod-desk-icon-btn--edit"
                            title="Edit"
                            aria-label={`Edit ${s.name}`}
                          >
                            <HiOutlinePencilSquare size={16} aria-hidden="true" />
                          </a>
                          <button
                            type="button"
                            className="prod-desk-icon-btn prod-desk-icon-btn--del"
                            title="Delete"
                            aria-label={`Delete ${s.name}`}
                            onClick={() => confirmDelete(s.id, s.name)}
                          >
                            <HiOutlineTrash size={16} aria-hidden="true" />
                          </button>
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
