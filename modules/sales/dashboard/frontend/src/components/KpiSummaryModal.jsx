import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  CalendarDays,
  ChevronDown,
  ChevronRight,
  FileText,
  Search,
  SlidersHorizontal,
  X,
} from 'lucide-react';
import {
  formatKpiStatValue,
  formatMoney,
  formatNumber,
  timeAgo,
} from '../utils/dashboardFormat.js';

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

function itemDate(item) {
  return String(item?.created_at || item?.due_date || '');
}

function isInThisMonth(dateStr) {
  if (!dateStr) return false;
  const normalized = dateStr.includes('T') ? dateStr : dateStr.replace(' ', 'T');
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return false;
  const now = new Date();
  return date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth();
}

function formatSheetDate(dateStr) {
  if (!dateStr) return '-';
  const normalized = dateStr.includes('T') ? dateStr : `${dateStr.replace(' ', 'T')}`;
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return dateStr;
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function statusLabel(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';
  return raw.replace(/_/g, ' ').replace(/\b\w/g, (ch) => ch.toUpperCase());
}

export default function KpiSummaryModal({ summary, summaryKey, onClose }) {
  const items = Array.isArray(summary?.items) ? summary.items : [];
  const [search, setSearch] = useState('');
  const [datePreset, setDatePreset] = useState('all');
  const [sort, setSort] = useState('date_desc');
  const [statusFilter, setStatusFilter] = useState('');
  const [filtersOpen, setFiltersOpen] = useState(false);

  useEffect(() => {
    if (!summary) return undefined;
    setDatePreset(items.some((item) => isInThisMonth(itemDate(item))) ? 'this_month' : 'all');
    setSearch('');
    setStatusFilter('');
    setFiltersOpen(false);
    setSort('date_desc');
    return undefined;
  }, [summaryKey, summary]);

  useEffect(() => {
    if (!summary) return undefined;
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
  }, [summary, onClose]);

  const statuses = useMemo(() => {
    const set = new Set();
    items.forEach((item) => {
      const label = statusLabel(item.status);
      if (label) set.add(label);
    });
    return [...set].sort();
  }, [items]);

  const visibleItems = useMemo(() => {
    const query = search.trim().toLowerCase();
    let rows = items.filter((item) => {
      if (datePreset === 'this_month' && !isInThisMonth(itemDate(item))) return false;
      if (statusFilter && statusLabel(item.status) !== statusFilter) return false;
      if (!query) return true;
      const hay = [item.ref_number, item.customer_name, item.status].join(' ').toLowerCase();
      return hay.includes(query);
    });

    rows = [...rows].sort((a, b) => {
      const da = String(itemDate(a) || '');
      const db = String(itemDate(b) || '');
      if (sort === 'date_asc') return da.localeCompare(db);
      if (sort === 'amount_desc') return (Number(b.total_amount) || 0) - (Number(a.total_amount) || 0);
      if (sort === 'amount_asc') return (Number(a.total_amount) || 0) - (Number(b.total_amount) || 0);
      return db.localeCompare(da);
    });
    return rows;
  }, [items, search, datePreset, sort, statusFilter]);

  if (!summary) return null;

  const headline = summary.headline_format === 'money'
    ? formatMoney(summary.headline)
    : formatNumber(summary.headline);
  const helper = summary.period_label || summary.items_heading || 'Contributing records';
  const showDueDate = summary.title === 'Overdue Invoices';

  return createPortal(
    <div className="pur-kpi-sheet-backdrop" onClick={onClose} role="presentation">
      <div
        className="pur-kpi-sheet"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sd-kpi-sheet-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="pur-kpi-sheet-handle" aria-hidden="true" />

        <header className="pur-kpi-sheet-head">
          <div className="pur-kpi-sheet-head-main">
            <div className="pur-kpi-sheet-icon" aria-hidden="true">
              <FileText size={18} strokeWidth={2} />
            </div>
            <div className="pur-kpi-sheet-head-copy">
              <h2 id="sd-kpi-sheet-title" className="pur-kpi-sheet-title">{summary.title}</h2>
              <p className="pur-kpi-sheet-headline">{headline}</p>
              <p className="pur-kpi-sheet-helper">{helper}</p>
            </div>
          </div>
          <button type="button" className="pur-kpi-sheet-close" onClick={onClose} aria-label="Close">
            <X size={18} />
          </button>
        </header>

        {summary.stats?.length ? (
          <section className="pur-kpi-sheet-confirm" aria-label="Breakdown">
            <h3 className="pur-kpi-sheet-confirm-title">Breakdown</h3>
            <p className="pur-kpi-sheet-confirm-text">
              {summary.stats.map((stat) => `${stat.label}: ${formatKpiStatValue(stat)}`).join(' · ')}
            </p>
          </section>
        ) : null}

        <div className="pur-kpi-sheet-tools">
          <div className="pur-kpi-sheet-tools-row">
            <label className="pur-kpi-sheet-date">
              <CalendarDays size={15} aria-hidden="true" />
              <select
                value={datePreset}
                onChange={(event) => setDatePreset(event.target.value)}
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
              onClick={() => setFiltersOpen((open) => !open)}
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
                  onChange={(event) => setStatusFilter(event.target.value)}
                >
                  <option value="">All statuses</option>
                  {statuses.map((status) => (
                    <option key={status} value={status}>{status}</option>
                  ))}
                </select>
              </label>
            </div>
          ) : null}

          <div className="pur-kpi-sheet-search">
            <Search size={16} className="pur-kpi-sheet-search-icon" aria-hidden="true" />
            <input
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search reference, customer..."
              aria-label="Search contributing records"
            />
          </div>
        </div>

        <div className="pur-kpi-sheet-list-head">
          <h3 className="pur-kpi-sheet-list-title">
            {summary.items_heading || 'Contributing Records'}
            {' '}
            ({visibleItems.length})
          </h3>
          <label className="pur-kpi-sheet-sort">
            <select
              value={sort}
              onChange={(event) => setSort(event.target.value)}
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
            <div className="pur-kpi-sheet-empty">No records match these filters.</div>
          ) : (
            visibleItems.map((item) => {
              const customer = String(item.customer_name || '-');
              const dateValue = itemDate(item);
              return (
                <button
                  key={`${item.ref_number}-${item.url}`}
                  type="button"
                  className="pur-kpi-sheet-card"
                  role="listitem"
                  onClick={() => {
                    if (!item.url) return;
                    try {
                      if (window.erpNavBack && typeof window.erpNavBack.push === 'function') {
                        window.erpNavBack.push({ href: window.location.href, force: true });
                      }
                    } catch {
                      /* ignore */
                    }
                    window.location.assign(item.url);
                  }}
                >
                  <div className="pur-kpi-sheet-card-top">
                    <div className="pur-kpi-sheet-card-main">
                      <span
                        className={`exp-desk-party-av exp-desk-party-av--tone-${partyAvatarTone(customer)}`}
                        aria-hidden="true"
                      >
                        {partyInitials(customer)}
                      </span>
                      <div className="pur-kpi-sheet-card-copy">
                        <span className="pur-kpi-sheet-card-ref">{item.ref_number || '-'}</span>
                        <span className="pur-kpi-sheet-card-payee">{customer}</span>
                      </div>
                    </div>
                    <div className="pur-kpi-sheet-card-aside">
                      <span className="pur-kpi-sheet-card-amt">{formatMoney(item.total_amount)}</span>
                      {item.status ? (
                        <span className="sd-kpi-sheet-status">{statusLabel(item.status)}</span>
                      ) : null}
                    </div>
                  </div>
                  <div className="pur-kpi-sheet-card-foot">
                    <div className="pur-kpi-sheet-card-fact">
                      <CalendarDays size={13} aria-hidden="true" />
                      <span>
                        {showDueDate && item.due_date
                          ? `Due ${formatSheetDate(item.due_date)}`
                          : (formatSheetDate(dateValue) || timeAgo(dateValue) || '-')}
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
