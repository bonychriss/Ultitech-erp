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
  HiOutlinePencilSquare,
  HiOutlinePlus,
  HiOutlineTrash,
  HiOutlineTruck,
  HiOutlineXCircle,
  HiOutlineXMark,
} from 'react-icons/hi2';
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

function readListStateFromUrl() {
  if (typeof window === 'undefined') {
    return { search: '', type: 'all', status: '', selectedId: 0 };
  }
  const p = new URLSearchParams(window.location.search);
  const typeRaw = String(p.get('type') || '').toLowerCase();
  const type = ['all', 'domestic', 'import'].includes(typeRaw) ? typeRaw : 'all';
  return {
    search: p.get('search') || p.get('q') || '',
    type,
    status: p.get('status') || '',
    selectedId: Number(p.get('sel') || 0) || 0,
  };
}

let consumedRestore;
function takeRestoredListState() {
  if (consumedRestore !== undefined) return consumedRestore;
  consumedRestore = null;
  if (typeof window === 'undefined' || !window.erpNavBack || typeof window.erpNavBack.consumeRestore !== 'function') {
    return null;
  }
  const restored = window.erpNavBack.consumeRestore();
  consumedRestore = restored && restored.state ? restored.state : null;
  return consumedRestore;
}

function writeListStateToUrl(search, typeFilter, statusFilter, selectedId) {
  if (typeof window === 'undefined' || !window.history?.replaceState) return;
  const url = new URL(window.location.href);
  const setOrDel = (key, value) => {
    const v = String(value || '').trim();
    if (v) url.searchParams.set(key, v);
    else url.searchParams.delete(key);
  };
  setOrDel('search', search);
  url.searchParams.delete('q');
  if (typeFilter && typeFilter !== 'all') url.searchParams.set('type', typeFilter);
  else url.searchParams.delete('type');
  setOrDel('status', statusFilter);
  if (selectedId) url.searchParams.set('sel', String(selectedId));
  else url.searchParams.delete('sel');

  // Keep PHP tab loaders aligned when the page is hard-reloaded.
  if (typeFilter === 'domestic') {
    url.searchParams.set('domestic', '1');
    url.searchParams.set('import', '0');
  } else if (typeFilter === 'import') {
    url.searchParams.set('domestic', '0');
    url.searchParams.set('import', '1');
  } else {
    url.searchParams.delete('domestic');
    url.searchParams.delete('import');
  }

  const next = `${url.pathname}${url.search}${url.hash}`;
  const cur = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  if (next !== cur) {
    window.history.replaceState(window.history.state, '', next);
  }
}

function buildListHref(search, typeFilter, statusFilter, selectedId) {
  if (typeof window === 'undefined') return '';
  const url = new URL(window.location.href);
  const setOrDel = (key, value) => {
    const v = String(value || '').trim();
    if (v) url.searchParams.set(key, v);
    else url.searchParams.delete(key);
  };
  setOrDel('search', search);
  url.searchParams.delete('q');
  if (typeFilter && typeFilter !== 'all') url.searchParams.set('type', typeFilter);
  else url.searchParams.delete('type');
  setOrDel('status', statusFilter);
  if (selectedId) url.searchParams.set('sel', String(selectedId));
  else url.searchParams.delete('sel');
  if (typeFilter === 'domestic') {
    url.searchParams.set('domestic', '1');
    url.searchParams.set('import', '0');
  } else if (typeFilter === 'import') {
    url.searchParams.set('domestic', '0');
    url.searchParams.set('import', '1');
  } else {
    url.searchParams.delete('domestic');
    url.searchParams.delete('import');
  }
  return `${url.pathname}${url.search}${url.hash}`;
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

function statusTone(status, className) {
  const raw = `${String(className || '')} ${String(status || '')}`.toLowerCase();
  if (raw.includes('received')) return 'po-ua-status--received';
  if (raw.includes('approved')) return 'po-ua-status--approved';
  if (raw.includes('reject')) return 'po-ua-status--rejected';
  if (raw.includes('pending') || raw.includes('awaiting') || raw.includes('negotiation') || raw.includes('supplier')) {
    return 'po-ua-status--pending';
  }
  if (raw.includes('draft')) return 'po-ua-status--draft';
  return 'po-ua-status--draft';
}

function StatusBadge({ status, label, className }) {
  return (
    <span className={`po-ua-status ${statusTone(status, className)}`} title={status || label || ''}>
      {label || status || '—'}
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

  const restored = takeRestoredListState();
  const urlList = readListStateFromUrl();
  const bootSearch = restored?.search != null
    ? String(restored.search)
    : (urlList.search || initialSearch || '');
  const bootType = ['all', 'domestic', 'import'].includes(restored?.typeFilter)
    ? restored.typeFilter
    : (['all', 'domestic', 'import'].includes(urlList.type)
      ? urlList.type
      : (['all', 'domestic', 'import'].includes(initialTab) ? initialTab : 'all'));
  const bootStatus = restored?.statusFilter != null
    ? String(restored.statusFilter)
    : (urlList.status || '');
  const bootSelectedId = Number(restored?.selectedId || urlList.selectedId || 0) || 0;

  const [purchases] = useState(initialPurchases);
  const [search, setSearch] = useState(bootSearch);
  const [typeFilter, setTypeFilter] = useState(bootType);
  const [statusFilter, setStatusFilter] = useState(bootStatus);
  const [draftType, setDraftType] = useState(bootType);
  const [draftStatus, setDraftStatus] = useState(bootStatus);
  const [selectedId, setSelectedId] = useState(bootSelectedId);
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
    writeListStateToUrl(search, typeFilter, statusFilter, selectedId);
  }, [search, typeFilter, statusFilter, selectedId]);

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

  useEffect(() => {
    if (!selectedId) return undefined;
    const t = window.setTimeout(() => {
      const row = document.querySelector(`[data-po-id="${selectedId}"]`);
      if (row && typeof row.scrollIntoView === 'function') {
        row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    }, 120);
    return () => window.clearTimeout(t);
  }, [selectedId, filtered.length]);

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

  const typeCounts = useMemo(() => {
    const base = purchases.filter((po) => {
      if (statusFilter && String(po.status || '') !== statusFilter) return false;
      return poMatchesQuery(search, po);
    });
    return {
      all: base.length,
      domestic: base.filter((po) => po.purchase_type !== 'import').length,
      import: base.filter((po) => po.purchase_type === 'import').length,
    };
  }, [purchases, search, statusFilter]);

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
    const go = () => {
      window.location.assign(href);
    };
    try {
      if (window.StockAlert?.confirm) {
        window.StockAlert.confirm(message, title, go);
        return;
      }
    } catch (_err) {
      // Fall through to native confirm if SweetAlert fails.
    }
    if (window.confirm(message)) go();
  };

  const rowKey = (po) => `${po.source || 'stock'}-${po.id}`;
  const withSource = (base, po) =>
    `${base}?id=${po.id}&source=${encodeURIComponent(po.source || 'stock')}`;

  const listState = () => ({
    search: search || '',
    typeFilter: typeFilter || 'all',
    statusFilter: statusFilter || '',
  });

  const rememberAndOpen = (base, po) => {
    const id = Number(po?.id) || 0;
    setSelectedId(id);
    writeListStateToUrl(search, typeFilter, statusFilter, id);
    const returnTo = buildListHref(search, typeFilter, statusFilter, id);
    if (typeof window !== 'undefined' && window.erpNavBack && typeof window.erpNavBack.push === 'function') {
      window.erpNavBack.push({
        href: returnTo || window.location.href,
        state: { ...listState(), selectedId: id },
        force: true,
      });
    }
    const dest = new URL(withSource(base, po), window.location.href);
    if (returnTo) dest.searchParams.set('return', returnTo);
    window.location.href = dest.href;
  };

  if (booting) {
    return (
      <div className="po-ua" aria-busy="true">
        <div className="po-ua-loading">Loading purchase orders…</div>
      </div>
    );
  }

  const typeFilterOptions = [
    ['all', 'All', typeCounts.all],
    ['domestic', 'Internal', typeCounts.domestic],
    ['import', 'Abroad', typeCounts.import],
  ];

  return (
    <div className="po-ua">
      <div className="po-ua-header">
        <div className="po-ua-search-wrap">
          <input
            type="search"
            className="po-ua-search"
            placeholder="Search number, supplier, product…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            aria-label="Search purchase orders"
          />
        </div>

        <div className="po-ua-header-actions">
          <div className={`po-ua-filter-dropdown${filtersOpen ? ' is-open' : ''}`} ref={filterWrapRef}>
            <button
              type="button"
              className={`po-ua-filter-btn${filtersOpen || filtersActive ? ' is-active' : ''}`}
              onClick={() => {
                setDraftType(typeFilter);
                setDraftStatus(statusFilter);
                setFiltersOpen((v) => !v);
              }}
              aria-expanded={filtersOpen}
              aria-haspopup="dialog"
              title="Filters"
              aria-label="Filters"
            >
              <HiOutlineAdjustmentsHorizontal size={18} aria-hidden="true" />
              {filtersActive ? <span className="po-ua-filter-dot" aria-hidden="true" /> : null}
            </button>
            {filtersOpen ? (
              <div className="po-ua-filter-panel" role="dialog" aria-label="Filter purchase orders">
                <div className="po-ua-filter-panel__head">
                  <div className="po-ua-filter-panel__heading">Filters</div>
                  <button
                    type="button"
                    className="po-ua-filter-clear"
                    disabled={!filtersActive}
                    onClick={clearAdvancedFilters}
                  >
                    Reset
                  </button>
                </div>

                <div className="po-ua-filter-panel__title">Type</div>
                <div className="po-ua-filter-panel__options">
                  {[
                    ['all', 'All'],
                    ['domestic', 'Internal'],
                    ['import', 'Abroad'],
                  ].map(([key, label]) => (
                    <button
                      key={key}
                      type="button"
                      className={`po-ua-filter-option${draftType === key ? ' is-active' : ''}`}
                      onClick={() => setDraftType(key)}
                    >
                      <span>{label}</span>
                    </button>
                  ))}
                </div>

                <div className="po-ua-filter-panel__title">Status</div>
                <div className="po-ua-filter-panel__options">
                  <button
                    type="button"
                    className={`po-ua-filter-option${draftStatus === '' ? ' is-active' : ''}`}
                    onClick={() => setDraftStatus('')}
                  >
                    <span>All statuses</span>
                  </button>
                  {statusOptions.map((o) => (
                    <button
                      key={o.value}
                      type="button"
                      className={`po-ua-filter-option${draftStatus === o.value ? ' is-active' : ''}`}
                      onClick={() => setDraftStatus(o.value)}
                    >
                      <span>{o.label}</span>
                    </button>
                  ))}
                </div>

                <div className="po-ua-filter-panel__footer">
                  <button type="button" className="po-ua-btn po-ua-btn--primary po-ua-btn--block" onClick={applyFilters}>
                    Done
                  </button>
                </div>
              </div>
            ) : null}
          </div>

          <a href={createDomesticUrl} className="po-ua-btn po-ua-btn--primary">
            <HiOutlinePlus size={16} aria-hidden="true" />
            <span className="po-ua-btn-label-desktop">Create PO</span>
            <span className="po-ua-btn-label-mobile">Create</span>
          </a>
        </div>
      </div>

      <section className="po-ua-kpis" aria-label="Purchases summary">
        <button type="button" className="po-ua-kpi" onClick={() => openKpiTrace('total')} title="View contributing POs">
          <div className="po-ua-kpi__text">
            <span className="po-ua-kpi__label">Total POs</span>
            <strong className="po-ua-kpi__value">{liveStats.total.toLocaleString('en-US')}</strong>
          </div>
          <span className="po-ua-kpi__badge po-ua-kpi__badge--violet" aria-hidden="true">
            <HiOutlineClipboardDocumentList size={18} />
          </span>
        </button>
        <button type="button" className="po-ua-kpi" onClick={() => openKpiTrace('approved')} title="View contributing POs">
          <div className="po-ua-kpi__text">
            <span className="po-ua-kpi__label">Approved</span>
            <strong className="po-ua-kpi__value">{liveStats.approved.toLocaleString('en-US')}</strong>
          </div>
          <span className="po-ua-kpi__badge po-ua-kpi__badge--teal" aria-hidden="true">
            <HiOutlineCheckCircle size={18} />
          </span>
        </button>
        <button type="button" className="po-ua-kpi" onClick={() => openKpiTrace('pending')} title="View contributing POs">
          <div className="po-ua-kpi__text">
            <span className="po-ua-kpi__label">Pending</span>
            <strong className="po-ua-kpi__value">{liveStats.pending.toLocaleString('en-US')}</strong>
          </div>
          <span className="po-ua-kpi__badge po-ua-kpi__badge--amber" aria-hidden="true">
            <HiOutlineClock size={18} />
          </span>
        </button>
        <button type="button" className="po-ua-kpi" onClick={() => openKpiTrace('value')} title="View contributing POs">
          <div className="po-ua-kpi__text">
            <span className="po-ua-kpi__label">Listed value</span>
            <strong className="po-ua-kpi__value">{formatMoney(liveStats.value)}</strong>
          </div>
          <span className="po-ua-kpi__badge po-ua-kpi__badge--blue" aria-hidden="true">
            <HiOutlineCurrencyDollar size={18} />
          </span>
        </button>
      </section>

      <section className="po-ua-desk" aria-label="Purchase orders">
        <div className="po-ua-desk__toolbar">
          <div className="po-ua-desk__heading">
            <h2 className="po-ua-desk__title">Purchase orders</h2>
            <p className="po-ua-desk__meta">
              {filtered.length.toLocaleString('en-US')} shown
              {typeFilter !== 'all' ? ` · ${typeFilter === 'import' ? 'Abroad' : 'Internal'}` : ''}
              {statusFilter
                ? ` · ${statusOptions.find((o) => o.value === statusFilter)?.label || statusFilter}`
                : typeFilter === 'all' && !search.trim()
                  ? ` · ${liveStats.domestic} internal · ${liveStats.abroad} abroad`
                  : ''}
            </p>
          </div>
          <div className="po-ua-filters" role="group" aria-label="Type filters">
            {typeFilterOptions.map(([key, label, count]) => (
              <button
                key={key}
                type="button"
                className={`po-ua-filter${typeFilter === key ? ' is-active' : ''}`}
                onClick={() => {
                  setTypeFilter(key);
                  setDraftType(key);
                }}
              >
                <span>{label}</span>
                <span className="po-ua-filter__count">{count}</span>
              </button>
            ))}
          </div>
        </div>

        {filtered.length === 0 ? (
          <div className="po-ua-table-card">
            <div className="po-ua-empty" role="status">
              <p className="po-ua-empty__title">
                {purchases.length === 0 ? 'No purchase orders' : 'No matching purchase orders'}
              </p>
              <p className="po-ua-empty__hint">
                {purchases.length === 0
                  ? 'Create a purchase order to get started.'
                  : 'Try clearing filters or widening the search.'}
              </p>
              {purchases.length === 0 ? (
                <div className="po-ua-empty__cta">
                  <a href={createDomesticUrl} className="po-ua-btn po-ua-btn--primary">
                    <HiOutlinePlus size={16} /> Create PO
                  </a>
                </div>
              ) : null}
            </div>
          </div>
        ) : (
          <div className="po-ua-table-card">
            <table className="po-ua-table">
              <colgroup>
                <col className="po-ua-col-number" />
                <col className="po-ua-col-supplier" />
                <col className="po-ua-col-type" />
                <col className="po-ua-col-money" />
                <col className="po-ua-col-status" />
                <col className="po-ua-col-actions" />
              </colgroup>
              <thead>
                <tr>
                  <th scope="col">Number</th>
                  <th scope="col">Supplier</th>
                  <th scope="col">Type</th>
                  <th scope="col" className="is-num">
                    Total
                  </th>
                  <th scope="col">Status</th>
                  <th scope="col">
                    <span className="po-ua-sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((po) => {
                  const key = rowKey(po);
                  const menuOpen = openId === key;
                  const number = po.purchase_no || `PO-${po.id}`;
                  const isImport = po.purchase_type === 'import';
                  return (
                    <tr
                      key={key}
                      data-po-id={po.id}
                      className={`po-ua-row${Number(selectedId) === Number(po.id) ? ' is-selected' : ''}`}
                      onClick={(e) => {
                        if (e.target.closest('a, button')) return;
                        rememberAndOpen(viewUrl, po);
                      }}
                    >
                      <td className="po-ua-cell po-ua-cell--doc">
                        <div className="po-ua-docnum">{number}</div>
                        <div className="po-ua-docdate">{formatDate(po.created_at)}</div>
                      </td>
                      <td className="po-ua-cell" title={po.supplier_name || ''}>
                        <div className="po-ua-payee">{po.supplier_name || '—'}</div>
                        <div className="po-ua-payee-sub" title={po.product_name || ''}>
                          {po.product_name || '—'}
                          {po.item_count > 1 ? ` (+${po.item_count - 1})` : ''}
                        </div>
                      </td>
                      <td className="po-ua-cell po-ua-cell--type" data-label="Type">
                        <span className={`po-ua-type ${isImport ? 'po-ua-type--import' : 'po-ua-type--domestic'}`}>
                          {isImport ? 'External' : 'Internal'}
                        </span>
                      </td>
                      <td className="po-ua-cell po-ua-cell--money" data-label="Total">
                        <div>{po.total_display || formatMoney(po.total_amount)}</div>
                        {po.payment_status ? (
                          <div className="po-ua-amount-sub">
                            {String(po.payment_status).charAt(0).toUpperCase() + String(po.payment_status).slice(1)}
                          </div>
                        ) : null}
                      </td>
                      <td className="po-ua-cell" data-label="Status">
                        <StatusBadge status={po.status} label={po.status_label} className={po.status_class} />
                      </td>
                      <td
                        className="po-ua-cell po-ua-cell--actions"
                        onClick={(e) => e.stopPropagation()}
                        ref={menuOpen ? menuRef : null}
                      >
                        <div className="po-ua-menu">
                          <button
                            type="button"
                            className="po-ua-menu__btn"
                            aria-label={`Actions for ${number}`}
                            aria-expanded={menuOpen}
                            aria-haspopup="menu"
                            onClick={() => setOpenId(menuOpen ? null : key)}
                          >
                            <HiOutlineEllipsisVertical size={16} />
                          </button>
                          {menuOpen ? (
                            <div className="po-ua-menu__panel po-ua-menu__panel--rich" role="menu" aria-label={`${number} actions`}>
                              <button
                                type="button"
                                role="menuitem"
                                className="po-ua-menu__item po-ua-menu__item--rich"
                                onClick={() => rememberAndOpen(viewUrl, po)}
                              >
                                <span className="po-ua-menu__icon">
                                  <HiOutlineDocumentText size={15} />
                                </span>
                                <span className="po-ua-menu__copy">
                                  <span className="po-ua-menu__label">Open</span>
                                  <span className="po-ua-menu__hint">View purchase order</span>
                                </span>
                              </button>

                              {po.can_edit ? (
                                <button
                                  type="button"
                                  role="menuitem"
                                  className="po-ua-menu__item po-ua-menu__item--rich"
                                  onClick={() => rememberAndOpen(editUrl, po)}
                                >
                                  <span className="po-ua-menu__icon">
                                    <HiOutlinePencilSquare size={15} />
                                  </span>
                                  <span className="po-ua-menu__copy">
                                    <span className="po-ua-menu__label">Edit</span>
                                    <span className="po-ua-menu__hint">Update this PO</span>
                                  </span>
                                </button>
                              ) : null}

                              {po.can_receive ? (
                                <button
                                  type="button"
                                  role="menuitem"
                                  className="po-ua-menu__item po-ua-menu__item--rich"
                                  onClick={() => rememberAndOpen(receiveUrl, po)}
                                >
                                  <span className="po-ua-menu__icon">
                                    <HiOutlineCheckBadge size={15} />
                                  </span>
                                  <span className="po-ua-menu__copy">
                                    <span className="po-ua-menu__label">Receive</span>
                                    <span className="po-ua-menu__hint">Receive stock</span>
                                  </span>
                                </button>
                              ) : null}

                              {isImport && !po.has_shipment ? (
                                <a href={`${shipmentCreateUrl}?purchase_id=${po.id}`} role="menuitem" className="po-ua-menu__item po-ua-menu__item--rich">
                                  <span className="po-ua-menu__icon">
                                    <HiOutlineTruck size={15} />
                                  </span>
                                  <span className="po-ua-menu__copy">
                                    <span className="po-ua-menu__label">Ship</span>
                                    <span className="po-ua-menu__hint">Create shipment</span>
                                  </span>
                                </a>
                              ) : null}

                              {isImport && po.has_shipment && po.linked_shipment_id ? (
                                <a href={`${shipmentViewUrl}?id=${po.linked_shipment_id}`} role="menuitem" className="po-ua-menu__item po-ua-menu__item--rich">
                                  <span className="po-ua-menu__icon">
                                    <HiOutlineTruck size={15} />
                                  </span>
                                  <span className="po-ua-menu__copy">
                                    <span className="po-ua-menu__label">Shipment</span>
                                    <span className="po-ua-menu__hint">View shipment</span>
                                  </span>
                                </a>
                              ) : null}

                              {po.has_invoice_attachment ? (
                                <a
                                  href={`${invoiceUrl}?id=${po.id}`}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  role="menuitem"
                                  className="po-ua-menu__item po-ua-menu__item--rich"
                                >
                                  <span className="po-ua-menu__icon">
                                    <HiOutlineDocumentText size={15} />
                                  </span>
                                  <span className="po-ua-menu__copy">
                                    <span className="po-ua-menu__label">Invoice</span>
                                    <span className="po-ua-menu__hint">Open attached invoice</span>
                                  </span>
                                </a>
                              ) : null}

                              <div className="po-ua-menu__sep" role="separator" />

                              <a href={`${cloneUrl}?clone_from_id=${po.id}`} role="menuitem" className="po-ua-menu__item po-ua-menu__item--rich">
                                <span className="po-ua-menu__icon">
                                  <HiOutlineDocumentDuplicate size={15} />
                                </span>
                                <span className="po-ua-menu__copy">
                                  <span className="po-ua-menu__label">Clone</span>
                                  <span className="po-ua-menu__hint">Duplicate this order</span>
                                </span>
                              </a>

                              {po.can_cancel ? (
                                <>
                                  <div className="po-ua-menu__sep" role="separator" />
                                  <button
                                    type="button"
                                    role="menuitem"
                                    className="po-ua-menu__item po-ua-menu__item--rich po-ua-menu__item--danger"
                                    onClick={() =>
                                      confirmGo('Cancel this order?', 'Cancel Order', withSource(cancelUrl, po))
                                    }
                                  >
                                    <span className="po-ua-menu__icon">
                                      <HiOutlineXCircle size={15} />
                                    </span>
                                    <span className="po-ua-menu__copy">
                                      <span className="po-ua-menu__label">Cancel</span>
                                      <span className="po-ua-menu__hint">Cancel this order</span>
                                    </span>
                                  </button>
                                </>
                              ) : null}

                              {isAdmin ? (
                                <>
                                  <div className="po-ua-menu__sep" role="separator" />
                                  <button
                                    type="button"
                                    role="menuitem"
                                    className="po-ua-menu__item po-ua-menu__item--rich po-ua-menu__item--danger"
                                    onClick={() =>
                                      confirmGo(
                                        'Permanently delete this purchase order? This cannot be undone.',
                                        'Delete Order',
                                        withSource(deleteUrl, po),
                                      )
                                    }
                                  >
                                    <span className="po-ua-menu__icon">
                                      <HiOutlineTrash size={15} />
                                    </span>
                                    <span className="po-ua-menu__copy">
                                      <span className="po-ua-menu__label">Delete</span>
                                      <span className="po-ua-menu__hint">Remove permanently</span>
                                    </span>
                                  </button>
                                </>
                              ) : null}
                            </div>
                          ) : null}
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

      {kpiTrace ? <PurchaseKpiTraceModal trace={kpiTrace} onClose={() => setKpiTrace(null)} /> : null}
    </div>
  );
}
