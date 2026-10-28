import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  ArrowLeftRight,
  CalendarDays,
  ChevronDown,
  ChevronRight,
  CreditCard,
  FileText,
  Search,
  SlidersHorizontal,
  Wallet,
  X,
} from 'lucide-react';
import ExpenseStatusBadge from './ExpenseStatusBadge';
import { formatKpiHeadline, purchasesKpiHelper } from '../utils/kpiTrace';

function formatCurrency(value, currencyCode = 'TZS') {
  const code = String(currencyCode || 'TZS').replace(/^TSh$/i, 'TZS');
  const amount = Number(value) || 0;
  return `${code} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(dateStr) {
  if (!dateStr) return '-';
  const normalized = dateStr.includes('T') ? dateStr : `${dateStr}T12:00:00`;
  return new Date(normalized).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function partyInitials(name) {
  const parts = String(name || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return `${parts[0][0] || ''}${parts[1][0] || ''}`.toUpperCase();
}

function partyAvatarTone(name) {
  const raw = String(name || '');
  let hash = 0;
  for (let i = 0; i < raw.length; i += 1) {
    hash = (hash + raw.charCodeAt(i) * (i + 1)) % 10;
  }
  return hash;
}

function paymentTypeIcon(item) {
  const haystack = [
    item?.paymentMethodLabel,
    item?.paymentMethod,
  ].map((v) => String(v || '').toLowerCase()).join(' ');
  if (haystack.includes('cash')) return Wallet;
  if (haystack.includes('credit') || haystack.includes('payable')) return CreditCard;
  return ArrowLeftRight;
}

function isInThisMonth(dateStr) {
  if (!dateStr) return false;
  const normalized = dateStr.includes('T') ? dateStr : `${dateStr}T12:00:00`;
  const d = new Date(normalized);
  if (Number.isNaN(d.getTime())) return false;
  const now = new Date();
  return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth();
}

function toExpenseShape(item) {
  return {
    display_status: item.displayStatus,
    status: item.status,
    is_posted: item.isPosted ? 1 : 0,
    is_paid: item.isPaid ? 1 : 0,
    is_voucher: item.isVoucher ? 1 : 0,
    source_type: item.sourceType || 'voucher',
  };
}

export default function PurchaseKpiTraceSheet({
  trace,
  traceKey,
  onClose,
  onOpenItem,
}) {
  const items = Array.isArray(trace.items) ? trace.items : [];
  const [search, setSearch] = useState('');
  const [datePreset, setDatePreset] = useState('all');
  const [sort, setSort] = useState('date_desc');
  const [statusFilter, setStatusFilter] = useState('');
  const [filtersOpen, setFiltersOpen] = useState(false);

  useEffect(() => {
    setDatePreset(items.some((item) => isInThisMonth(item.date)) ? 'this_month' : 'all');
    setSearch('');
    setStatusFilter('');
    setFiltersOpen(false);
    setSort('date_desc');
    // Reset controls when opening a different KPI
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [traceKey]);

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    function handleKeyDown(event) {
      if (event.key === 'Escape') onClose();
    }
    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [onClose]);

  const visibleItems = useMemo(() => {
    const q = search.trim().toLowerCase();
    let rows = items.filter((item) => {
      if (datePreset === 'this_month' && !isInThisMonth(item.date)) return false;
      if (statusFilter) {
        const label = String(item.displayStatus || item.status || '').toLowerCase();
        if (label !== statusFilter) return false;
      }
      if (!q) return true;
      const hay = [
        item.expenseNumber,
        item.payee,
        item.paymentMethodLabel,
        item.categoryName,
      ].join(' ').toLowerCase();
      return hay.includes(q);
    });

    rows = [...rows].sort((a, b) => {
      const da = String(a.date || '');
      const db = String(b.date || '');
      if (sort === 'date_asc') return da.localeCompare(db);
      if (sort === 'amount_desc') return (Number(b.grossAmount) || 0) - (Number(a.grossAmount) || 0);
      if (sort === 'amount_asc') return (Number(a.grossAmount) || 0) - (Number(b.grossAmount) || 0);
      return db.localeCompare(da);
    });
    return rows;
  }, [items, search, datePreset, sort, statusFilter]);

  const helper = purchasesKpiHelper(traceKey) || 'Total voucher purchases';

  return createPortal(
    <div className="pur-kpi-sheet-backdrop" onClick={onClose} role="presentation">
      <div
        className="pur-kpi-sheet"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pur-kpi-sheet-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="pur-kpi-sheet-handle" aria-hidden="true" />

        <header className="pur-kpi-sheet-head">
          <div className="pur-kpi-sheet-head-main">
            <div className="pur-kpi-sheet-icon" aria-hidden="true">
              <FileText size={18} strokeWidth={2} />
            </div>
            <div className="pur-kpi-sheet-head-copy">
              <h2 id="pur-kpi-sheet-title" className="pur-kpi-sheet-title">{trace.title}</h2>
              <p className="pur-kpi-sheet-headline">{formatKpiHeadline(trace)}</p>
              <p className="pur-kpi-sheet-helper">{helper}</p>
            </div>
          </div>
          <button type="button" className="pur-kpi-sheet-close" onClick={onClose} aria-label="Close">
            <X size={18} />
          </button>
        </header>

        {trace.confirmation ? (
          <section className="pur-kpi-sheet-confirm" aria-label="Confirmation">
            <h3 className="pur-kpi-sheet-confirm-title">Confirmation</h3>
            <p className="pur-kpi-sheet-confirm-text">{trace.confirmation}</p>
          </section>
        ) : null}

        <div className="pur-kpi-sheet-tools">
          <div className="pur-kpi-sheet-tools-row">
            <label className="pur-kpi-sheet-date">
              <CalendarDays size={15} aria-hidden="true" />
              <select
                value={datePreset}
                onChange={(e) => setDatePreset(e.target.value)}
                aria-label="Date range"
              >
                <option value="this_month">This month</option>
                <option value="all">All dates</option>
              </select>
              <ChevronDown size={14} aria-hidden="true" />
            </label>
            <button
              type="button"
              className={`pur-kpi-sheet-filters-btn${filtersOpen || statusFilter ? ' is-active' : ''}`}
              onClick={() => setFiltersOpen((v) => !v)}
              aria-label="Filters"
              title="Filters"
            >
              <SlidersHorizontal size={18} strokeWidth={2.25} aria-hidden="true" />
            </button>
          </div>

          {filtersOpen ? (
            <div className="pur-kpi-sheet-filters-panel">
              <label className="pur-kpi-sheet-filter-field">
                <span>Status</span>
                <select
                  value={statusFilter}
                  onChange={(e) => setStatusFilter(e.target.value)}
                >
                  <option value="">All statuses</option>
                  <option value="pending">Pending</option>
                  <option value="posted">Posted</option>
                  <option value="approved">Approved</option>
                  <option value="paid">Paid</option>
                </select>
              </label>
            </div>
          ) : null}

          <div className="pur-kpi-sheet-search">
            <Search size={16} className="pur-kpi-sheet-search-icon" aria-hidden="true" />
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search voucher no, payee, description..."
              aria-label="Search contributing records"
            />
          </div>
        </div>

        <div className="pur-kpi-sheet-list-head">
          <h3 className="pur-kpi-sheet-list-title">
            Contributing Records ({visibleItems.length})
          </h3>
          <label className="pur-kpi-sheet-sort">
            <select
              value={sort}
              onChange={(e) => setSort(e.target.value)}
              aria-label="Sort records"
            >
              <option value="date_desc">Date (Latest)</option>
              <option value="date_asc">Date (Oldest)</option>
              <option value="amount_desc">Amount (High)</option>
              <option value="amount_asc">Amount (Low)</option>
            </select>
            <ChevronDown size={14} aria-hidden="true" />
          </label>
        </div>

        <div className="pur-kpi-sheet-list" role="list">
          {visibleItems.length === 0 ? (
            <div className="pur-kpi-sheet-empty">No purchases match these filters.</div>
          ) : (
            visibleItems.map((item) => {
              const payee = String(item.payee || '-');
              const TypeIcon = paymentTypeIcon(item);
              const methodLabel = String(item.paymentMethodLabel || '').trim() || '-';
              const accountLabel = String(item.categoryName || '').trim();
              return (
                <button
                  key={`${item.rowKey || item.id}-${item.expenseNumber}`}
                  type="button"
                  className="pur-kpi-sheet-card"
                  role="listitem"
                  onClick={() => {
                    if (typeof onOpenItem === 'function') onOpenItem(item);
                    else if (item.voucherUrl) window.location.href = item.voucherUrl;
                  }}
                >
                  <div className="pur-kpi-sheet-card-top">
                    <div className="pur-kpi-sheet-card-main">
                      <span
                        className={`exp-desk-party-av exp-desk-party-av--tone-${partyAvatarTone(payee)}`}
                        aria-hidden="true"
                      >
                        {partyInitials(payee)}
                      </span>
                      <div className="pur-kpi-sheet-card-copy">
                        <span className="pur-kpi-sheet-card-ref">{item.expenseNumber || '-'}</span>
                        <span className="pur-kpi-sheet-card-payee">{payee}</span>
                      </div>
                    </div>
                    <div className="pur-kpi-sheet-card-aside">
                      <span className="pur-kpi-sheet-card-amt">
                        {formatCurrency(item.grossAmount || item.amount, item.currency)}
                      </span>
                      <ExpenseStatusBadge expense={toExpenseShape(item)} />
                    </div>
                  </div>
                  <div className="pur-kpi-sheet-card-foot">
                    <div className="pur-kpi-sheet-card-fact">
                      <CalendarDays size={13} aria-hidden="true" />
                      <span>{formatDate(item.date)}</span>
                    </div>
                    <div className="pur-kpi-sheet-card-fact">
                      <TypeIcon size={13} aria-hidden="true" />
                      <span>
                        {methodLabel}
                        {accountLabel ? ` / ${accountLabel}` : ''}
                      </span>
                    </div>
                    <ChevronRight size={16} className="pur-kpi-sheet-card-chevron" aria-hidden="true" />
                  </div>
                </button>
              );
            })
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
}
