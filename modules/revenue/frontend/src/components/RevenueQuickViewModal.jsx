import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { FileText, Paperclip, Pencil, Wallet, X } from 'lucide-react';
import RevenueStatusBadge from './RevenueStatusBadge';
import { deskPageUrl } from '../api/revenueDesk';
import { formatDateDigits, formatVoucherRef } from '../utils/formatDisplay';
import { revenueInvoiceHref } from '../utils/revenueNav';

function formatCurrency(value) {
  const amount = Number(value) || 0;
  return `TZS ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(dateStr) {
  return formatDateDigits(dateStr);
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

function documentKindMeta(kind) {
  if (kind === 'delivery_note') {
    return { label: 'Delivery note', Icon: FileText };
  }
  if (kind === 'payment_proof') {
    return { label: 'Proof of payment', Icon: Paperclip };
  }
  return { label: 'Attachment', Icon: Paperclip };
}

function DocumentsBlock({ documents }) {
  const list = Array.isArray(documents) ? documents : [];
  if (list.length === 0) return null;

  const deliveryNotes = list.filter((doc) => doc.kind === 'delivery_note');
  const paymentProofs = list.filter((doc) => doc.kind === 'payment_proof');
  const other = list.filter((doc) => doc.kind !== 'delivery_note' && doc.kind !== 'payment_proof');

  const sections = [
    { key: 'delivery', title: 'Delivery note', items: deliveryNotes },
    { key: 'payment', title: 'Proof of payment', items: paymentProofs },
    { key: 'other', title: 'Attachments', items: other },
  ].filter((section) => section.items.length > 0);

  return (
    <div className="rev-desk-quick-docs">
      {sections.map((section) => (
        <div key={section.key} className="rev-desk-quick-docs-section">
          <div className="rev-desk-quick-docs-label">{section.title}</div>
          <ul className="rev-desk-quick-docs-list">
            {section.items.map((doc, index) => {
              const meta = documentKindMeta(doc.kind);
              const Icon = meta.Icon;
              const href = String(doc.url || '').trim();
              const name = String(doc.name || meta.label);
              if (!href) return null;
              return (
                <li key={`${doc.kind}-${index}-${name}`}>
                  <a
                    href={href}
                    className="rev-desk-quick-doc-link"
                    target="_blank"
                    rel="noopener noreferrer"
                    title={`Open ${meta.label}`}
                  >
                    <Icon size={14} aria-hidden="true" />
                    <span>{name}</span>
                  </a>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
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
  const invoiceUrl = invoiceNumber ? revenueInvoiceHref(entry) : '';
  const invoiceLink = invoiceUrl ? (
    <a
      href={invoiceUrl}
      className="rev-desk-quick-link"
      title={`Open invoice ${invoiceNumber}`}
      onClick={(event) => {
        event.preventDefault();
        onRemember?.(entry.id);
        const href = revenueInvoiceHref(entry);
        if (href) window.location.href = href;
      }}
    >
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
              {formatVoucherRef(entry.voucher_number, `REV #${entry.id}`)}
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
          <DocumentsBlock documents={entry.documents} />
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
