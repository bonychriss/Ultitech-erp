import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  HiOutlineAdjustmentsHorizontal,
  HiOutlineCheckBadge,
  HiOutlineCheckCircle,
  HiOutlineClipboardDocumentList,
  HiOutlineClock,
  HiOutlineCurrencyDollar,
  HiOutlineDocumentDuplicate,
  HiOutlineDocumentText,
  HiOutlineEllipsisVertical,
  HiOutlineMagnifyingGlass,
  HiOutlinePencilSquare,
  HiOutlinePlus,
  HiOutlineTrash,
  HiOutlineTruck,
  HiOutlineXCircle,
  HiOutlineXMark,
} from 'react-icons/hi2';
import './products-desk.css';
import './purchases-desk.css';
import PurchaseKpiTraceModal from './PurchaseKpiTraceModal';

const PENDING_STATUSES = [
  'Draft',
  'Pending',
  'Pending Supplier',
  'Pending Approval',
  'Supplier Responded',
  'Negotiation Requested',
];

const KPI_ITEM_LIMIT = 150;

function normalizeSearchQuery(q) {
  return String(q || '')
    .trim()
    .replace(/\s+/g, ' ');
}

function poMatchesQuery(q, po) {
  const tokens = normalizeSearchQuery(q)
    .toLowerCase()
    .split(' ')
    .filter(Boolean);
  if (!tokens.length) return true;
  const hay = [
    po.purchase_no,
    po.supplier_name,
    po.product_name,
    po.product_code,
    po.status,
    po.status_label,
    po.purchase_type,
    po.payment_status,
  ]
    .map((v) => String(v || '').toLowerCase())
    .join(' ');
  return tokens.every((t) => hay.includes(t));
}

function formatDate(d) {
  if (!d || !String(d).trim()) return '—';
  const t = Date.parse(d);
  if (Number.isNaN(t)) return '—';
  return new Date(t).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function StatusBadge({ status, label, className }) {
  return (
    <span className={`po-desk-badge ${className || 'po-desk-badge--draft'}`} title={status || label || ''}>
      {label || status || '—'}
    </span>
  );
}

function TypeBadge({ type }) {
  const isImport = type === 'import';
  return (
    <span className={`po-desk-badge ${isImport ? 'po-desk-badge--import' : 'po-desk-badge--domestic'}`}>
      {isImport ? 'Abroad' : 'Internal'}
    </span>
  );
}

export default function PurchasesList({ data }) {
  const {
    purchases: initialPurchases = [],
    search: initialSearch = '',
    activeTab: initialTab = 'all',
    isAdmin = false,
    currencySymbol = '$',
    urls = {},
    createSuccess = null,
    flashMessage = '',
    flashType = 'success',
  } = data;

  const createDomesticUrl = urls.createDomestic || 'domestic_create.php';
  const viewUrl = urls.view || 'view_po.php';
  const editUrl = urls.edit || 'edit.php';
  const receiveUrl = urls.receive || 'domestic_receive.php';
  const cancelUrl = urls.cancel || 'cancel.php';
  const deleteUrl = urls.delete || 'delete.php';
  const cloneUrl = urls.clone || 'create.php';
  const shipmentCreateUrl = urls.shipmentCreate || '../shipments/create.php';
  const shipmentViewUrl = urls.shipmentView || '../shipments/view.php';
  const invoiceUrl = urls.invoice || 'download_invoice.php';

  const [purchases] = useState(initialPurchases);
  const [search, setSearch] = useState(initialSearch);
  const [typeFilter, setTypeFilter] = useState(
    ['all', 'domestic', 'import'].includes(initialTab) ? initialTab : 'all'
  );
  const [statusFilter, setStatusFilter] = useState('');
  const [draftType, setDraftType] = useState(
    ['all', 'domestic', 'import'].includes(initialTab) ? initialTab : 'all'
  );
  const [draftStatus, setDraftStatus] = useState('');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [openId, setOpenId] = useState(null);
  const [booting, setBooting] = useState(true);
  const [kpiTrace, setKpiTrace] = useState(null);
  const filterWrapRef = useRef(null);
  const menuRef = useRef(null);

  useEffect(() => {
    const t = window.setTimeout(() => setBooting(false), 200);
    return () => window.clearTimeout(t);
  }, []);

  useEffect(() => {
    if (createSuccess && window.Swal) {
      window.Swal.fire({
        title: createSuccess.title || 'Success',
        text: createSuccess.message || '',
        icon: createSuccess.variant === 'warning' ? 'warning' : 'success',
        confirmButtonColor: '#4f46e5',
        confirmButtonText: 'OK',
      });
      return;
    }
    if (flashMessage && window.Swal) {
      window.Swal.fire({
        toast: true,
        position: 'top-end',
        icon: flashType === 'error' ? 'error' : 'success',
        title: flashMessage,
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true,
      });
    }
  }, [createSuccess, flashMessage, flashType]);

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

  useEffect(() => {
    if (openId == null) return undefined;
    const onDown = (e) => {
      if (menuRef.current && !menuRef.current.contains(e.target)) setOpenId(null);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setOpenId(null);
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [openId]);

  const statusOptions = useMemo(() => {
    const set = new Map();
    purchases.forEach((po) => {
      const key = String(po.status || '');
      if (!key) return;
      if (!set.has(key)) set.set(key, po.status_label || key);
    });
    return Array.from(set.entries()).map(([value, label]) => ({ value, label }));
  }, [purchases]);

  const filtered = useMemo(() => {
    return purchases.filter((po) => {
      if (typeFilter === 'domestic' && po.purchase_type !== 'domestic') return false;
      if (typeFilter === 'import' && po.purchase_type !== 'import') return false;
      if (statusFilter && String(po.status || '') !== statusFilter) return false;
      return poMatchesQuery(search, po);
    });
  }, [purchases, search, typeFilter, statusFilter]);

  const liveStats = useMemo(() => {
    let approved = 0;
    let received = 0;
    let pending = 0;
    let other = 0;
    let value = 0;
    let domestic = 0;
    let abroad = 0;
    let domesticValue = 0;
    let abroadValue = 0;

    filtered.forEach((po) => {
      const st = String(po.status || '');
      const amt = Number(po.total_amount_converted) || Number(po.total_amount) || 0;
      value += amt;
      if (po.purchase_type === 'import') {
        abroad += 1;
        abroadValue += amt;
      } else {
        domestic += 1;
        domesticValue += amt;
      }

      if (st === 'Received') {
        received += 1;
        approved += 1;
      } else if (st === 'Approved') {
        approved += 1;
      } else if (PENDING_STATUSES.includes(st)) {
        pending += 1;
      } else {
        other += 1;
      }
    });

    return {
      total: filtered.length,
      approved,
      received,
      pending,
      other,
      value,
      domestic,
      abroad,
      domesticValue,
      abroadValue,
      avgValue: filtered.length ? value / filtered.length : 0,
    };
  }, [filtered]);

  const formatMoney = (n) =>
    `${currencySymbol}${(Number(n) || 0).toLocaleString('en', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })}`;

  const mapPoToTraceItem = (po) => {
    const amount = Number(po.total_amount_converted) || Number(po.total_amount) || 0;
    return {
      id: po.id,
      purchaseNo: po.purchase_no || `PO-${po.id}`,
      date: po.created_at || '',
      supplier: po.supplier_name || '-',
      typeLabel: po.purchase_type === 'import' ? 'Abroad' : 'Internal',
      statusLabel: po.status_label || po.status || '-',
      amount,
      amountDisplay: po.total_display || formatMoney(amount),
    };
  };

  const openKpiTrace = (key) => {
    const filterNote =
      typeFilter !== 'all' || statusFilter || search.trim()
        ? 'Based on the current search and filters.'
        : 'Based on all purchase orders currently loaded.';

    let rows = filtered;
    let title = '';
    let headline = '';
    let confirmation = '';
    let footnote = '';

    if (key === 'total') {
      title = 'Total POs';
      headline = String(liveStats.total);
      confirmation = `${liveStats.total} purchase ${liveStats.total === 1 ? 'order is' : 'orders are'} in this list. ${filterNote}`;
      footnote = `Internal ${liveStats.domestic} - Abroad ${liveStats.abroad} - Approved/received ${liveStats.approved} - Pending ${liveStats.pending}`;
    } else if (key === 'approved') {
      rows = filtered.filter((po) => {
        const st = String(po.status || '');
        return st === 'Approved' || st === 'Received';
      });
      title = 'Approved';
      headline = String(liveStats.approved);
      confirmation = `${liveStats.approved} ${liveStats.approved === 1 ? 'order is' : 'orders are'} Approved or Received. ${filterNote}`;
      footnote = `Approved (not received) ${Math.max(0, liveStats.approved - liveStats.received)} - Received ${liveStats.received}`;
    } else if (key === 'pending') {
      rows = filtered.filter((po) => PENDING_STATUSES.includes(String(po.status || '')));
      title = 'Pending';
      headline = String(liveStats.pending);
      confirmation =
        liveStats.pending === 0
          ? 'No pending purchase orders in the current list.'
          : `${liveStats.pending} ${liveStats.pending === 1 ? 'order is' : 'orders are'} still pending. ${filterNote}`;
      footnote = 'Includes Draft, Pending, Pending Supplier, Pending Approval, Supplier Responded, and Negotiation Requested.';
    } else if (key === 'value') {
      title = 'Listed value';
      headline = formatMoney(liveStats.value);
      confirmation = `Sum of PO amounts in this list is ${formatMoney(liveStats.value)}. ${filterNote}`;
      footnote = `Internal ${formatMoney(liveStats.domesticValue)} - Abroad ${formatMoney(liveStats.abroadValue)} - Avg ${formatMoney(liveStats.avgValue)}`;
    } else {
      return;
    }

    const totalCount = rows.length;
    const visible = rows.slice(0, KPI_ITEM_LIMIT);
    const items = visible.map(mapPoToTraceItem);
    const shownValue = items.reduce((sum, row) => sum + (Number(row.amount) || 0), 0);

    if (totalCount > KPI_ITEM_LIMIT) {
      footnote = `${footnote ? `${footnote} - ` : ''}Showing first ${KPI_ITEM_LIMIT} of ${totalCount} records.`;
    }

    setKpiTrace({
      key,
      title,
      headline,
      confirmation,
      footnote,
      items,
      totalCount,
      currencySymbol,
      totalDisplay: formatMoney(key === 'value' ? liveStats.value : shownValue),
    });
  };

  const filtersActive = typeFilter !== 'all' || statusFilter !== '';

  const applyFilters = () => {
    setTypeFilter(draftType);
    setStatusFilter(draftStatus);
    setFiltersOpen(false);
  };

  const clearAdvancedFilters = () => {
    setDraftType('all');
    setDraftStatus('');
    setTypeFilter('all');
    setStatusFilter('');
    setFiltersOpen(false);
  };

  const confirmGo = (message, title, href) => {
    if (window.StockAlert?.confirm) {
      window.StockAlert.confirm(message, title, () => {
        window.location.href = href;
      });
      return;
    }
    if (window.confirm(message)) window.location.href = href;
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
    <div className="prod-desk-page po-desk">
      <div className="prod-desk-page-header">
        <div className="prod-desk-page-header-search">
          <div className="prod-desk-search-field">
            <HiOutlineMagnifyingGlass className="prod-desk-search-icon" aria-hidden="true" />
            <input
              type="search"
              className="prod-desk-search-input"
              placeholder="Search PO #, supplier, product..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              aria-label="Search purchase orders"
            />
            {search.trim() ? (
              <button
                type="button"
                className="po-desk-search-clear"
                onClick={() => setSearch('')}
                aria-label="Clear search"
              >
                <HiOutlineXMark size={16} />
              </button>
            ) : null}
          </div>
        </div>

        <div className="prod-desk-page-header-actions">
          <div className="prod-desk-filter-wrap" ref={filterWrapRef}>
            <button
              type="button"
              className={`prod-desk-filter-btn${filtersOpen || filtersActive ? ' is-active' : ''}`}
              onClick={() => {
                setDraftType(typeFilter);
                setDraftStatus(statusFilter);
                setFiltersOpen((v) => !v);
              }}
              aria-expanded={filtersOpen}
              title="Filters"
            >
              <HiOutlineAdjustmentsHorizontal size={18} aria-hidden="true" />
              {filtersActive ? <span className="prod-desk-filter-dot" aria-hidden="true" /> : null}
            </button>
            {filtersOpen ? (
              <div className="prod-desk-filter-panel po-desk-filter-panel" role="dialog" aria-label="Purchase filters">
                <div className="po-desk-filters-head">
                  <div>
                    <h2>Filters</h2>
                    <p>Narrow by type and status.</p>
                  </div>
                  <button type="button" className="po-desk-filters-close" onClick={() => setFiltersOpen(false)} aria-label="Close">
                    <HiOutlineXMark size={16} />
                  </button>
                </div>
                <div className="prod-desk-filter-grid">
                  <div>
                    <label htmlFor="po-filter-type">Type</label>
                    <select
                      id="po-filter-type"
                      value={draftType}
                      onChange={(e) => setDraftType(e.target.value)}
                    >
                      <option value="all">All</option>
                      <option value="domestic">Internal</option>
                      <option value="import">Abroad</option>
                    </select>
                  </div>
                  <div>
                    <label htmlFor="po-filter-status">Status</label>
                    <select
                      id="po-filter-status"
                      value={draftStatus}
                      onChange={(e) => setDraftStatus(e.target.value)}
                    >
                      <option value="">All statuses</option>
                      {statusOptions.map((o) => (
                        <option key={o.value} value={o.value}>
                          {o.label}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
                <div className="prod-desk-filter-actions">
                  <button type="button" className="prod-desk-btn prod-desk-btn-secondary" onClick={clearAdvancedFilters}>
                    Clear
                  </button>
                  <button type="button" className="prod-desk-btn prod-desk-btn-primary" onClick={applyFilters}>
                    Apply
                  </button>
                </div>
              </div>
            ) : null}
          </div>

          <a href={createDomesticUrl} className="prod-desk-btn prod-desk-btn-primary">
            <HiOutlinePlus size={16} aria-hidden="true" />
            <span className="po-desk-btn-label-desktop">New PO</span>
            <span className="po-desk-btn-label-mobile">New</span>
          </a>
        </div>
      </div>

      {filtersActive ? (
        <div className="po-desk-active-filters">
          {typeFilter !== 'all' ? (
            <button
              type="button"
              className="po-desk-filter-chip"
              onClick={() => {
                setTypeFilter('all');
                setDraftType('all');
              }}
            >
              Type: {typeFilter === 'import' ? 'Abroad' : 'Internal'}
              <HiOutlineXMark size={12} />
            </button>
          ) : null}
          {statusFilter ? (
            <button
              type="button"
              className="po-desk-filter-chip"
              onClick={() => {
                setStatusFilter('');
                setDraftStatus('');
              }}
            >
              Status: {statusOptions.find((o) => o.value === statusFilter)?.label || statusFilter}
              <HiOutlineXMark size={12} />
            </button>
          ) : null}
          <button type="button" className="po-desk-filter-chip po-desk-filter-chip--clear" onClick={clearAdvancedFilters}>
            Clear filters
          </button>
        </div>
      ) : null}

      <section className="prod-desk-kpi-grid" aria-label="Summary">
        <button
          type="button"
          className="prod-desk-kpi-card po-desk-kpi-card--clickable"
          onClick={() => openKpiTrace('total')}
          aria-label="View how total POs is calculated"
          title="Click to see contributing purchase orders"
        >
          <div className="prod-desk-kpi-icon prod-desk-kpi-icon--violet">
            <HiOutlineClipboardDocumentList size={18} />
          </div>
          <div>
            <div className="prod-desk-kpi-label">total POs</div>
            <div className="prod-desk-kpi-value">{liveStats.total}</div>
          </div>
        </button>
        <button
          type="button"
          className="prod-desk-kpi-card po-desk-kpi-card--clickable"
          onClick={() => openKpiTrace('approved')}
          aria-label="View how approved is calculated"
          title="Click to see contributing purchase orders"
        >
          <div className="prod-desk-kpi-icon prod-desk-kpi-icon--teal">
            <HiOutlineCheckCircle size={18} />
          </div>
          <div>
            <div className="prod-desk-kpi-label">approved</div>
            <div className="prod-desk-kpi-value">{liveStats.approved}</div>
          </div>
        </button>
        <button
          type="button"
          className="prod-desk-kpi-card po-desk-kpi-card--clickable"
          onClick={() => openKpiTrace('pending')}
          aria-label="View how pending is calculated"
          title="Click to see contributing purchase orders"
        >
          <div className="prod-desk-kpi-icon prod-desk-kpi-icon--amber">
            <HiOutlineClock size={18} />
          </div>
          <div>
            <div className="prod-desk-kpi-label">pending</div>
            <div className="prod-desk-kpi-value">{liveStats.pending}</div>
          </div>
        </button>
        <button
          type="button"
          className="prod-desk-kpi-card po-desk-kpi-card--clickable"
          onClick={() => openKpiTrace('value')}
          aria-label="View how listed value is calculated"
          title="Click to see contributing purchase orders"
        >
          <div className="prod-desk-kpi-icon prod-desk-kpi-icon--indigo">
            <HiOutlineCurrencyDollar size={18} />
          </div>
          <div>
            <div className="prod-desk-kpi-label">listed value</div>
            <div className="prod-desk-kpi-value po-desk-kpi-money">
              {currencySymbol}
              {liveStats.value.toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </div>
          </div>
        </button>
      </section>

      <section className="prod-desk-results">
        <div className="prod-desk-results-head">
          <span className="prod-desk-results-count">
            {filtered.length} {filtered.length === 1 ? 'result' : 'results'}
          </span>
        </div>

        {filtered.length === 0 ? (
          <div className="po-desk-empty" role="status">
            <div className="po-desk-empty-icon" aria-hidden="true">
              <span className="po-desk-empty-ring" />
              <HiOutlineClipboardDocumentList size={34} />
            </div>
            <p className="po-desk-empty-title">
              {purchases.length === 0 ? 'No purchase orders yet' : 'No matching purchase orders'}
            </p>
            <p className="po-desk-empty-hint">
              {purchases.length === 0
                ? 'Create an internal or abroad purchase order to get started.'
                : 'Try another search or clear filters.'}
            </p>
            {purchases.length === 0 ? (
              <div className="po-desk-empty-cta">
                <a href={createDomesticUrl} className="prod-desk-btn prod-desk-btn-primary">
                  <HiOutlinePlus size={16} /> New PO
                </a>
              </div>
            ) : null}
          </div>
        ) : (
          <div className="prod-desk-table-wrap po-desk-table-wrap">
            <table className="prod-desk-table po-desk-table">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Product</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th className="is-num">Amount</th>
                  <th className="is-actions">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((po) => (
                  <tr
                    key={`${po.source || 'stock'}-${po.id}`}
                    onClick={(e) => {
                      if (e.target.closest('a, button')) return;
                      window.location.href = `${viewUrl}?id=${po.id}`;
                    }}
                  >
                    <td>
                      <a
                        className="po-desk-ref"
                        href={`${viewUrl}?id=${po.id}`}
                        onClick={(e) => e.stopPropagation()}
                      >
                        {po.purchase_no || `PO #${po.id}`}
                      </a>
                      <div className="po-desk-sub">{formatDate(po.created_at)}</div>
                    </td>
                    <td>
                      <div className="po-desk-cell-main" title={po.product_name || ''}>
                        {po.product_name || '—'}
                        {po.item_count > 1 ? ` (+${po.item_count - 1})` : ''}
                      </div>
                      <div className="po-desk-cell-sub" title={po.supplier_name || ''}>
                        {po.supplier_name || '—'}
                      </div>
                    </td>
                    <td>
                      <TypeBadge type={po.purchase_type} />
                    </td>
                    <td>
                      <StatusBadge
                        status={po.status}
                        label={po.status_label}
                        className={po.status_class}
                      />
                    </td>
                    <td className="is-num">
                      <span className="po-desk-amt">{po.total_display || '—'}</span>
                      {po.payment_status ? (
                        <div className="po-desk-cell-sub">
                          {String(po.payment_status).charAt(0).toUpperCase() + String(po.payment_status).slice(1)}
                        </div>
                      ) : null}
                    </td>
                    <td
                      className="is-actions"
                      onClick={(e) => e.stopPropagation()}
                      ref={openId === po.id ? menuRef : null}
                    >
                      <div className="po-desk-actions">
                        <button
                          type="button"
                          className="prod-desk-icon-btn"
                          title="More actions"
                          aria-expanded={openId === po.id}
                          onClick={() => setOpenId(openId === po.id ? null : po.id)}
                        >
                          <HiOutlineEllipsisVertical size={16} />
                        </button>
                        {openId === po.id ? (
                          <div className="po-desk-menu" role="menu">
                            <a href={`${viewUrl}?id=${po.id}`} role="menuitem">
                              <HiOutlineDocumentText size={15} /> View PO
                            </a>
                            {po.can_edit ? (
                              <a href={`${editUrl}?id=${po.id}`} role="menuitem">
                                <HiOutlinePencilSquare size={15} /> Edit
                              </a>
                            ) : null}
                            {po.can_receive ? (
                              <a href={`${receiveUrl}?id=${po.id}`} role="menuitem">
                                <HiOutlineCheckBadge size={15} /> Receive stock
                              </a>
                            ) : null}
                            {po.purchase_type === 'import' && !po.has_shipment ? (
                              <a href={`${shipmentCreateUrl}?purchase_id=${po.id}`} role="menuitem">
                                <HiOutlineTruck size={15} /> Create shipment
                              </a>
                            ) : null}
                            {po.purchase_type === 'import' && po.has_shipment && po.linked_shipment_id ? (
                              <a href={`${shipmentViewUrl}?id=${po.linked_shipment_id}`} role="menuitem">
                                <HiOutlineTruck size={15} /> View shipment
                              </a>
                            ) : null}
                            {po.has_invoice_attachment ? (
                              <a href={`${invoiceUrl}?id=${po.id}`} target="_blank" rel="noopener noreferrer" role="menuitem">
                                <HiOutlineDocumentText size={15} /> View invoice
                              </a>
                            ) : null}
                            <div className="po-desk-menu-div" />
                            <a href={`${cloneUrl}?clone_from_id=${po.id}`} role="menuitem">
                              <HiOutlineDocumentDuplicate size={15} /> Clone order
                            </a>
                            {po.can_cancel ? (
                              <>
                                <div className="po-desk-menu-div" />
                                <button
                                  type="button"
                                  className="is-danger"
                                  role="menuitem"
                                  onClick={() =>
                                    confirmGo('Cancel this order?', 'Cancel Order', `${cancelUrl}?id=${po.id}`)
                                  }
                                >
                                  <HiOutlineXCircle size={15} /> Cancel order
                                </button>
                              </>
                            ) : null}
                            {isAdmin ? (
                              <>
                                <div className="po-desk-menu-div" />
                                <button
                                  type="button"
                                  className="is-danger"
                                  role="menuitem"
                                  onClick={() =>
                                    confirmGo(
                                      'Permanently delete this purchase order? This cannot be undone.',
                                      'Delete Order',
                                      `${deleteUrl}?id=${po.id}`
                                    )
                                  }
                                >
                                  <HiOutlineTrash size={15} /> Delete order
                                </button>
                              </>
                            ) : null}
                          </div>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {kpiTrace ? <PurchaseKpiTraceModal trace={kpiTrace} onClose={() => setKpiTrace(null)} /> : null}
    </div>
  );
}
