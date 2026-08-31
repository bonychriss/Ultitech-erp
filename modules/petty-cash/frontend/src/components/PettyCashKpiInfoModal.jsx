import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { formatDate } from '../utils/format.js';

function formatCurrency(value) {
  const amount = Number(value) || 0;
  return `TZS ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/**
 * @param {'float'|'spent'|'pending_vouchers'|'pending_topups'} key
 */
export function resolvePettyCashKpiInfo(key, { stats = {}, vouchers = [], replenishments = [] } = {}) {
  const pendingVouchers = vouchers.filter((row) => String(row.status || '').toLowerCase() === 'pending');
  const pendingTopups = replenishments.filter((row) => String(row.status || '').toLowerCase() === 'pending');

  switch (key) {
    case 'float':
      return {
        key,
        title: 'Float balance',
        value: formatCurrency(stats.total_balance),
        summary: 'Cash currently held in the petty cash float.',
        details: [
          { label: 'Available float', value: formatCurrency(stats.total_balance) },
          { label: 'Approved vouchers', value: String(stats.approved_vouchers ?? 0) },
          { label: 'Pending vouchers', value: String(stats.pending_vouchers ?? 0) },
        ],
        items: [],
        footnote: 'Float decreases when vouchers are approved and increases when top-ups are approved.',
      };
    case 'spent':
      return {
        key,
        title: 'Total spent',
        value: formatCurrency(stats.total_spent),
        summary: 'Sum of all approved petty cash vouchers.',
        details: [
          { label: 'Approved spend', value: formatCurrency(stats.total_spent) },
          { label: 'Approved vouchers', value: String(stats.approved_vouchers ?? 0) },
        ],
        items: vouchers
          .filter((row) => String(row.status || '').toLowerCase() === 'approved')
          .slice(0, 8)
          .map((row) => ({
            id: row.id,
            primary: row.voucher_number || `#${row.id}`,
            secondary: row.description || row.category || '-',
            meta: formatDate(row.date),
            amount: formatCurrency(row.amount),
            href: row.view_url,
          })),
        footnote: 'Only approved vouchers are counted in total spent.',
      };
    case 'pending_vouchers':
      return {
        key,
        title: 'Pending vouchers',
        value: String(stats.pending_vouchers ?? pendingVouchers.length),
        summary: 'Vouchers waiting for approval before float is deducted.',
        details: [
          { label: 'Pending count', value: String(stats.pending_vouchers ?? pendingVouchers.length) },
          {
            label: 'Pending amount (listed)',
            value: formatCurrency(pendingVouchers.reduce((sum, row) => sum + (Number(row.amount) || 0), 0)),
          },
        ],
        items: pendingVouchers.slice(0, 10).map((row) => ({
          id: row.id,
          primary: row.voucher_number || `#${row.id}`,
          secondary: row.description || row.category || '-',
          meta: formatDate(row.date),
          amount: formatCurrency(row.amount),
          href: row.view_url,
        })),
        footnote: 'Approve a voucher to post it to Balances and reduce float.',
      };
    case 'pending_topups':
      return {
        key,
        title: 'Pending top-ups',
        value: String(stats.pending_replenishments ?? pendingTopups.length),
        summary: 'Replenishment requests waiting for confirmation.',
        details: [
          { label: 'Pending count', value: String(stats.pending_replenishments ?? pendingTopups.length) },
          {
            label: 'Pending amount (listed)',
            value: formatCurrency(pendingTopups.reduce((sum, row) => sum + (Number(row.amount) || 0), 0)),
          },
        ],
        items: pendingTopups.slice(0, 10).map((row) => ({
          id: row.id,
          primary: row.replenishment_number || `#${row.id}`,
          secondary: row.custodian_name || row.description || '-',
          meta: formatDate(row.created_at),
          amount: formatCurrency(row.amount),
          href: row.view_url || row.confirm_url,
        })),
        footnote: 'Approved top-ups move funds into the petty cash account.',
      };
    default:
      return null;
  }
}

export default function PettyCashKpiInfoModal({ info, onClose }) {
  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    function handleKeyDown(event) {
      if (event.key === 'Escape') onClose?.();
    }
    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [onClose]);

  if (!info || typeof document === 'undefined') return null;

  return createPortal(
    <div className="exp-desk-modal-backdrop" onClick={onClose} role="presentation">
      <div
        className="exp-desk-modal pc-kpi-info-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pc-kpi-info-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="exp-desk-quick-head">
          <div className="exp-desk-quick-head-main">
            <h2 id="pc-kpi-info-title" className="exp-desk-quick-title">
              {info.title}
            </h2>
            <p className="exp-desk-quick-subtitle">{info.value}</p>
          </div>
          <button type="button" className="exp-desk-quick-close" onClick={onClose} aria-label="Close">
            <X size={18} aria-hidden />
          </button>
        </div>

        <div className="exp-desk-quick-body pc-kpi-info-body">
          <p className="pc-kpi-info-summary">{info.summary}</p>

          {Array.isArray(info.details) && info.details.length > 0 ? (
            <dl className="pc-kpi-info-details">
              {info.details.map((row) => (
                <div key={row.label}>
                  <dt>{row.label}</dt>
                  <dd>{row.value}</dd>
                </div>
              ))}
            </dl>
          ) : null}

          {Array.isArray(info.items) && info.items.length > 0 ? (
            <div className="pc-kpi-info-items">
              <div className="pc-kpi-info-items-title">Related items</div>
              <ul>
                {info.items.map((item) => (
                  <li key={item.id}>
                    {item.href ? (
                      <a href={item.href} className="pc-kpi-info-item">
                        <span className="pc-kpi-info-item__main">
                          <strong>{item.primary}</strong>
                          <span>{item.secondary}</span>
                        </span>
                        <span className="pc-kpi-info-item__side">
                          <span>{item.amount}</span>
                          <span>{item.meta}</span>
                        </span>
                      </a>
                    ) : (
                      <div className="pc-kpi-info-item">
                        <span className="pc-kpi-info-item__main">
                          <strong>{item.primary}</strong>
                          <span>{item.secondary}</span>
                        </span>
                        <span className="pc-kpi-info-item__side">
                          <span>{item.amount}</span>
                          <span>{item.meta}</span>
                        </span>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          {info.footnote ? <p className="pc-kpi-info-footnote">{info.footnote}</p> : null}
        </div>
      </div>
    </div>,
    document.body,
  );
}
