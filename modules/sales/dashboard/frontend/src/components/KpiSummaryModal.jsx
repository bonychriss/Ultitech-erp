import { useEffect } from 'react';
import { X } from 'lucide-react';
import {
  formatKpiStatValue,
  formatMoney,
  formatNumber,
  timeAgo,
} from '../utils/dashboardFormat.js';

function SummaryItemRow({ item, showDueDate = false }) {
  return (
    <div className="sd-kpi-modal-item">
      <div className="sd-kpi-modal-item-main">
        <a href={item.url} className="sd-kpi-modal-item-ref">{item.ref_number}</a>
        <span className="sd-kpi-modal-item-customer">{item.customer_name}</span>
      </div>
      <div className="sd-kpi-modal-item-meta">
        <span className="sd-kpi-modal-item-amount">{formatMoney(item.total_amount)}</span>
        {showDueDate && item.due_date ? (
          <span className="sd-kpi-modal-item-date">Due {item.due_date}</span>
        ) : null}
        {!showDueDate && item.status ? (
          <span className="sd-kpi-modal-item-status">{item.status}</span>
        ) : null}
        {!showDueDate && item.created_at ? (
          <span className="sd-kpi-modal-item-date">{timeAgo(item.created_at)}</span>
        ) : null}
      </div>
    </div>
  );
}

export default function KpiSummaryModal({ summary, onClose }) {
  useEffect(() => {
    if (!summary) return undefined;
    const onKeyDown = (event) => {
      if (event.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [summary, onClose]);

  if (!summary) return null;

  const headline = summary.headline_format === 'money'
    ? formatMoney(summary.headline)
    : formatNumber(summary.headline);

  const showDueDate = summary.title === 'Overdue Invoices';

  return (
    <div
      className="sd-kpi-modal-backdrop"
      role="presentation"
      onClick={onClose}
    >
      <div
        className="sd-kpi-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sd-kpi-modal-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="sd-kpi-modal-head">
          <div>
            <h2 id="sd-kpi-modal-title">{summary.title}</h2>
            {summary.period_label ? (
              <p className="sd-kpi-modal-period">{summary.period_label}</p>
            ) : null}
          </div>
          <button type="button" className="sd-kpi-modal-close" onClick={onClose} aria-label="Close">
            <X size={18} />
          </button>
        </div>

        <div className="sd-kpi-modal-headline">{headline}</div>

        <div className="sd-kpi-modal-stats">
          {(summary.stats || []).map((stat) => (
            <div className="sd-kpi-modal-stat" key={stat.label}>
              <span className="sd-kpi-modal-stat-label">{stat.label}</span>
              <span className="sd-kpi-modal-stat-value">{formatKpiStatValue(stat)}</span>
            </div>
          ))}
        </div>

        {summary.items_heading ? (
          <h3 className="sd-kpi-modal-items-title">{summary.items_heading}</h3>
        ) : null}

        {summary.items?.length ? (
          <div className="sd-kpi-modal-items">
            {summary.items.map((item) => (
              <SummaryItemRow
                key={`${item.ref_number}-${item.url}`}
                item={item}
                showDueDate={showDueDate}
              />
            ))}
          </div>
        ) : (
          <p className="sd-kpi-modal-empty">No line items to show.</p>
        )}

        {summary.action_url ? (
          <div className="sd-kpi-modal-actions">
            <a href={summary.action_url} className="exp-desk-btn exp-desk-btn-primary">
              {summary.action_label || 'View details'}
            </a>
            <button type="button" className="exp-desk-btn exp-desk-btn-secondary" onClick={onClose}>
              Close
            </button>
          </div>
        ) : null}
      </div>
    </div>
  );
}
