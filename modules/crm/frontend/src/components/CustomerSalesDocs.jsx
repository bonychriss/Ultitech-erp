import { useEffect, useRef, useState } from 'react';
import SalesDocViewModal, { buildSalesDocPreviewUrl } from './SalesDocViewModal.jsx';
import SalesDocDownloadModal from './SalesDocDownloadModal.jsx';
import { downloadSalesDocPdf } from '../utils/downloadSalesDocPdf.js';

const AVATAR_STYLES = [
  { background: '#e0f2fe', color: '#0369a1' },
  { background: '#ede9fe', color: '#6d28d9' },
  { background: '#d1fae5', color: '#047857' },
  { background: '#fef3c7', color: '#b45309' },
  { background: '#ffe4e6', color: '#be123c' },
  { background: '#cffafe', color: '#0e7490' },
  { background: '#e0e7ff', color: '#4338ca' },
  { background: '#ccfbf1', color: '#0f766e' },
];

function initials(name) {
  if (!name || !String(name).trim()) return '?';
  return String(name).split(/\s+/).map((w) => w[0]).join('').toUpperCase().slice(0, 2);
}

function avatarStyle(name) {
  const s = name && String(name).trim() || '';
  if (!s) return { background: '#f3f4f6', color: '#6b7280' };
  let h = 0;
  for (let i = 0; i < s.length; i++) h = (h << 5) - h + s.charCodeAt(i) | 0;
  return AVATAR_STYLES[Math.abs(h) % AVATAR_STYLES.length];
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount || 0);
}

function formatTableDate(value) {
  if (!value) return '-';
  const date = new Date(String(value).includes('T') ? value : String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return String(value).slice(0, 10);
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;
}

function TypeBadge({ type }) {
  const t = String(type || 'spare').toLowerCase();
  if (t === 'truck') {
    return (
      <span className="crm-qt-type-badge crm-qt-type-badge--truck">
        <i className="fas fa-truck" aria-hidden="true" />
        TRUCK
      </span>
    );
  }
  return (
    <span className="crm-qt-type-badge crm-qt-type-badge--spare">
      <i className="fas fa-wrench" aria-hidden="true" />
      SPARE
    </span>
  );
}

function SalespersonCell({ name }) {
  const label = String(name || '').trim() || '-';
  return (
    <div className="crm-qt-salesperson-cell">
      <span className="crm-qt-avatar" style={avatarStyle(label === '-' ? '' : label)}>{initials(label === '-' ? '' : label)}</span>
      <span className="crm-qt-salesperson-name" title={label}>{label}</span>
    </div>
  );
}

function RowActionsMenu({ viewUrl, downloadUrl, rowKey, onView, onDownload }) {
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (!open) return undefined;
    const close = (ev) => {
      if (ev.target?.closest?.('[data-crm-doc-actions]')) return;
      setOpen(false);
    };
    document.addEventListener('click', close);
    return () => document.removeEventListener('click', close);
  }, [open]);

  if (!viewUrl && !downloadUrl) {
    return <span className="crm-doc-actions-empty">-</span>;
  }

  return (
    <div className="crm-qt-actions-menu" data-crm-doc-actions="1">
      <button
        type="button"
        className="crm-qt-actions-btn"
        aria-label="Actions"
        aria-expanded={open}
        onClick={(e) => {
          e.stopPropagation();
          setOpen((prev) => !prev);
        }}
      >
        <i className="fas fa-ellipsis-vertical" aria-hidden="true" />
      </button>
      {open ? (
        <div className="crm-qt-actions-dropdown" id={`crm-doc-actions-${rowKey}`}>
          {viewUrl ? (
            <button
              type="button"
              className="crm-qt-actions-item"
              onClick={(e) => {
                e.stopPropagation();
                setOpen(false);
                onView?.();
              }}
            >
              <i className="fas fa-eye" style={{ width: 20, color: '#94a3b8' }} aria-hidden="true" />
              View
            </button>
          ) : null}
          {downloadUrl ? (
            <button
              type="button"
              className="crm-qt-actions-item"
              onClick={(e) => {
                e.stopPropagation();
                setOpen(false);
                onDownload?.();
              }}
            >
              <i className="fas fa-download" style={{ width: 20, color: '#94a3b8' }} aria-hidden="true" />
              Download
            </button>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function SalesDocTable({
  rows,
  emptyMessage,
  totalIcon,
  totalTone,
  totalTitle,
  totalAmount,
  totalCount,
  countLabel,
  showOrderType = false,
  onViewRow,
  onDownloadRow,
}) {
  return (
    <div className="crm-table-wrap">
      <table className="crm-table crm-doc-table">
        <colgroup>
          <col className="crm-col-doc-number" />
          <col className="crm-col-doc-salesperson" />
          <col className="crm-col-doc-date" />
          <col className="crm-col-doc-amount" />
          <col className="crm-col-doc-actions" />
        </colgroup>
        <thead>
          <tr>
            <th className="crm-doc-col-number">Number</th>
            <th>Salesperson</th>
            <th className="crm-doc-col-date">Date</th>
            <th className="crm-table-amount">Total</th>
            <th className="crm-doc-col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          {rows?.length ? (
            rows.map((row) => (
              <tr
                key={`${row.id}-${row.number}`}
                className={(row.view_url || row.download_url) ? 'crm-doc-row-clickable' : undefined}
                tabIndex={(row.view_url || row.download_url) ? 0 : undefined}
                onClick={() => {
                  if (row.view_url || row.download_url) onViewRow?.(row);
                }}
                onKeyDown={(event) => {
                  if (!row.view_url && !row.download_url) return;
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onViewRow?.(row);
                  }
                }}
              >
                <td className="crm-doc-col-number">
                  <span className="crm-doc-ref">{row.number || `#${row.id}`}</span>
                  {showOrderType && row.order_type ? (
                    <div className="crm-doc-cell-sub"><TypeBadge type={row.order_type} /></div>
                  ) : null}
                </td>
                <td className="crm-doc-col-salesperson">
                  <SalespersonCell name={row.salesperson} />
                </td>
                <td className="crm-doc-col-date"><span className="crm-doc-subdate">{formatTableDate(row.date)}</span></td>
                <td className="crm-table-amount">
                  <span className="crm-doc-amt">{formatCurrency(row.amount)}</span>
                </td>
                <td className="crm-doc-col-actions" onClick={(e) => e.stopPropagation()}>
                  <RowActionsMenu
                    viewUrl={row.view_url}
                    downloadUrl={row.download_url}
                    rowKey={`${row.id}-${row.number}`}
                    onView={() => onViewRow?.(row)}
                    onDownload={() => onDownloadRow?.(row)}
                  />
                </td>
              </tr>
            ))
          ) : (
            <tr>
              <td colSpan={5} className="crm-doc-empty">{emptyMessage}</td>
            </tr>
          )}
        </tbody>
        <tfoot>
          <tr className={`crm-doc-total-row crm-doc-total-row--${totalTone}`}>
            <td colSpan={3}>
              <div className="crm-doc-total-cell">
                <span className={`crm-doc-total-icon crm-doc-total-icon--${totalTone}`} aria-hidden="true">
                  {totalIcon}
                </span>
                <span className="crm-doc-total-title">{totalTitle}</span>
                <span className="crm-doc-total-meta">{totalCount || 0} {countLabel}</span>
              </div>
            </td>
            <td className="crm-table-amount crm-doc-total-value">{totalAmount || '-'}</td>
            <td />
          </tr>
        </tfoot>
      </table>
    </div>
  );
}

export default function CustomerSalesDocs({ sales, loading }) {
  const [viewer, setViewer] = useState(null);
  const [downloadState, setDownloadState] = useState(null);
  const downloadRunRef = useRef(0);

  const openViewer = (row, docLabel) => {
    const previewUrl = buildSalesDocPreviewUrl(row.download_url);
    if (!previewUrl) return;
    setViewer({
      title: `${docLabel} ${row.number || `#${row.id}`}`,
      docLabel,
      row,
      previewUrl,
      downloadUrl: row.download_url || '',
    });
  };

  const startDownload = async (row, docLabel) => {
    if (!row.download_url || downloadState?.phase === 'downloading') return;

    const runId = downloadRunRef.current + 1;
    downloadRunRef.current = runId;
    const title = `${docLabel} ${row.number || `#${row.id}`}`;

    setDownloadState({
      title,
      phase: 'downloading',
      progress: 0,
      message: 'Preparing download...',
    });

    try {
      await downloadSalesDocPdf(row.download_url, (progress, message, phase) => {
        if (downloadRunRef.current !== runId) return;
        setDownloadState({
          title,
          phase: phase === 'success' ? 'success' : 'downloading',
          progress,
          message,
        });
      });

      if (downloadRunRef.current !== runId) return;

      setDownloadState({
        title,
        phase: 'success',
        progress: 100,
        message: 'Download complete',
      });

      window.setTimeout(() => {
        if (downloadRunRef.current === runId) {
          setDownloadState(null);
        }
      }, 1400);
    } catch (err) {
      if (downloadRunRef.current !== runId) return;
      setDownloadState({
        title,
        phase: 'error',
        progress: 0,
        message: err instanceof Error ? err.message : 'Failed to download PDF.',
      });
    }
  };

  const closeDownload = () => {
    downloadRunRef.current += 1;
    setDownloadState(null);
  };

  if (loading) {
    return (
      <div className="ms-loading">
        <span>Loading quotes and invoices...</span>
      </div>
    );
  }

  if (!sales) return null;

  const totals = sales.totals || {};
  const quotes = sales.quotes || [];
  const invoices = sales.invoices || [];
  const showOrderType = Boolean(sales.supports_order_type_split);

  if (!sales.linked) {
    return <div className="ms-empty">This contact is not linked to a Sales customer yet.</div>;
  }

  return (
    <>
      <div className="crm-sales-docs">
        <div className="crm-sales-docs-col">
          <div className="crm-panel">
            <div className="crm-panel-head">
              Quotes
              {quotes.length > 0 ? ` (${quotes.length})` : ''}
            </div>
            <SalesDocTable
              rows={quotes}
              emptyMessage="No quotes yet."
              totalIcon="Q"
              totalTone="quotes"
              totalTitle="Quotes Total"
              totalAmount={totals.quotes_total_formatted}
              totalCount={totals.quotes_count}
              countLabel="quote(s)"
              showOrderType={showOrderType}
              onViewRow={(row) => openViewer(row, 'Quotation')}
              onDownloadRow={(row) => startDownload(row, 'Quotation')}
            />
          </div>
        </div>

        <div className="crm-sales-docs-col">
          <div className="crm-panel">
            <div className="crm-panel-head">
              Invoices
              {invoices.length > 0 ? ` (${invoices.length})` : ''}
            </div>
            <SalesDocTable
              rows={invoices}
              emptyMessage="No invoices yet."
              totalIcon="I"
              totalTone="invoices"
              totalTitle="Invoices Total"
              totalAmount={totals.invoices_total_formatted}
              totalCount={totals.invoices_count}
              countLabel="invoice(s)"
              onViewRow={(row) => openViewer(row, 'Invoice')}
              onDownloadRow={(row) => startDownload(row, 'Invoice')}
            />
          </div>
        </div>
      </div>

      <SalesDocViewModal
        open={Boolean(viewer)}
        title={viewer?.title || ''}
        previewUrl={viewer?.previewUrl || ''}
        downloadUrl={viewer?.downloadUrl || ''}
        onDownload={() => {
          if (!viewer?.row || !viewer?.docLabel) return;
          startDownload(viewer.row, viewer.docLabel);
        }}
        onClose={() => setViewer(null)}
      />

      <SalesDocDownloadModal
        open={Boolean(downloadState)}
        title={downloadState?.title || ''}
        phase={downloadState?.phase || 'downloading'}
        progress={downloadState?.progress || 0}
        message={downloadState?.message || ''}
        onClose={closeDownload}
      />
    </>
  );
}

export function formatDocDate(value) {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value).slice(0, 10);
  return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export function formatDocStatus(status) {
  const text = String(status || '').trim();
  if (!text) return '-';
  return text.replace(/_/g, ' ');
}
