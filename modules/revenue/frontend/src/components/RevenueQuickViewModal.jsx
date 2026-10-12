import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { Pencil, Wallet, X } from 'lucide-react';
import RevenueStatusBadge from './RevenueStatusBadge';
import { deskPageUrl } from '../api/revenueDesk';

function formatCurrency(value) {
  const amount = Number(value) || 0;
  return `TZS ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
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

/** Invoice page URL that brings the user back to this list with the same entry reopened. */
function invoiceHref(entry) {
  const base = String(entry.invoice_view_url || '');
  if (!base) return '';
  const back = new URL(window.location.href);
  back.searchParams.set('sel', String(entry.id));
  back.searchParams.set('preview', String(entry.id));
  const returnTo = back.pathname + back.search;
  return `${base}${base.includes('?') ? '&' : '?'}return=${encodeURIComponent(returnTo)}`;
}

function withInvoiceLink(text, invoiceNumber, link) {
  const value = String(text || '');
  const at = invoiceNumber ? value.indexOf(invoiceNumber) : -1;
  if (!link || at < 0) return value;
  return (
    <>
      {value.slice(0, at)}
      {link}
      {value.slice(at + invoiceNumber.length)}
    </>
  );
}

function detailRow(label, value, { amount = false } = {}) {
  if (value === null || value === undefined || value === '' || value === '-') return null;
  return (
    <div className="rev-desk-quick-row">
      <dt>{label}</dt>
      <dd className={amount ? 'rev-desk-quick-amt' : undefined}>{value}</dd>
    </div>
  );
}

export default function RevenueQuickViewModal({
  entry,
  onClose,
  onPay,
  onRemember,
}) {
  const vatAmount = Number(entry.vat_amount) || 0;
  const balanceDue = Number(entry.balance_due) || 0;
  const showActions = entry.can_edit || entry.can_pay;
  const invoiceNumber = String(entry.linked_invoice_number || '');
  const invoiceUrl = invoiceNumber ? invoiceHref(entry) : '';
  const invoiceLink = invoiceUrl ? (
    <a href={invoiceUrl} className="rev-desk-quick-link" title={`Open invoice ${invoiceNumber}`}>
      {invoiceNumber}
    </a>
  ) : null;

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

  return createPortal(
    <div className="rev-desk-modal-backdrop" onClick={onClose} role="presentation">
      <div
        className="rev-desk-modal rev-desk-quick-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rev-desk-quick-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="rev-desk-quick-head">
          <div className="rev-desk-quick-head-main">
            <h2 id="rev-desk-quick-title" className="rev-desk-quick-title">
              {entry.voucher_number || `REV #${entry.id}`}
            </h2>
            <p className="rev-desk-quick-subtitle">{entry.customer_display || entry.customer_name || '-'}</p>
            <div className="rev-desk-quick-meta">
              <RevenueStatusBadge entry={entry} />
              <span className={`rev-type rev-type--${entry.type_class || 'other'}`}>{entry.type_label}</span>
              <span className="rev-desk-quick-date">{formatDate(entry.entry_date)}</span>
            </div>
          </div>
          <button type="button" className="rev-desk-quick-close" onClick={onClose} aria-label="Close">
            <X size={20} aria-hidden="true" />
          </button>
        </div>

        <div className="rev-desk-quick-body">
          <dl className="rev-desk-quick-details">
            {detailRow('Total', formatCurrency(entry.amount_total), { amount: true })}
            {detailRow('Paid', formatCurrency(entry.amount_paid), { amount: true })}
            {balanceDue > 0.009 && detailRow('Balance', formatCurrency(balanceDue), { amount: true })}
            {invoiceNumber && detailRow('Invoice', invoiceLink || invoiceNumber)}
            {entry.customer_code_display && entry.customer_code_display !== '-'
              && detailRow('Customer code', entry.customer_code_display)}
            {entry.payment_mode && detailRow('Payment method', entry.payment_mode)}
            {vatAmount > 0 && detailRow('VAT', formatCurrency(vatAmount), { amount: true })}
            {detailRow('Description', withInvoiceLink(entry.description || entry.narration, invoiceNumber, invoiceLink))}
          </dl>
        </div>

        {showActions ? (
          <div className="rev-desk-quick-actions">
            {entry.can_edit ? (
              <a
                href={deskPageUrl('revenue_edit.php', { id: entry.id })}
                className="rev-desk-btn rev-desk-btn-ghost rev-desk-quick-action-btn"
                onClick={() => onRemember?.(entry.id)}
              >
                <Pencil size={16} aria-hidden="true" />
                Edit
              </a>
            ) : null}
            {entry.can_pay ? (
              <button
                type="button"
                className="rev-desk-btn rev-desk-btn-primary rev-desk-quick-action-btn"
                onClick={() => {
                  onClose();
                  onPay?.(entry.id);
                }}
              >
                <Wallet size={16} aria-hidden="true" />
                Record payment
              </button>
            ) : null}
          </div>
        ) : null}
      </div>
    </div>,
    document.body,
  );
}
