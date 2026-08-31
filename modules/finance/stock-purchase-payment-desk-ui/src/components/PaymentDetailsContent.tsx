import { type ReactNode } from 'react';
import { ExternalLink, FileText } from 'lucide-react';
import type { PurchaseOrderAttachment, PurchaseOrderDetails, PurchaseOrderPayment } from '../types';
import {
  balanceDueColorClass,
  formatDate,
  formatMoney,
  isPaidStatus,
} from '../utils/format';

function formatFileSize(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '-';
  if (bytes < 1024) return `${bytes} B`;
  return `${(bytes / 1024).toFixed(1)} KB`;
}

function InfoItem({ label, value, className = '' }: { label: string; value: ReactNode; className?: string }) {
  return (
    <div className={`sppd-info-item ${className}`}>
      <span className="sppd-info-label">{label}</span>
      <span className="sppd-info-value">{value}</span>
    </div>
  );
}

interface PaymentDetailsContentProps {
  details: PurchaseOrderDetails;
}

export default function PaymentDetailsContent({ details }: PaymentDetailsContentProps) {
  const { order, payments, attachments = [] } = details;
  const isPaid = isPaidStatus(order.paymentStatus);

  return (
    <div className="sppd-details-body">
      <section className="sppd-details-amounts-row" aria-label="Amounts">
        <article className="sppd-details-amount-card">
          <span className="sppd-details-amount-label">PO total</span>
          <span className="sppd-details-amount-value">{formatMoney(order.amountToPay, order.currency)}</span>
        </article>
        <article className="sppd-details-amount-card">
          <span className="sppd-details-amount-label">Paid</span>
          <span className="sppd-details-amount-value sppd-amt-paid">
            {formatMoney(order.amountPaid, order.currency)}
          </span>
        </article>
        <article className={`sppd-details-amount-card sppd-details-amount-card--due${isPaid ? ' is-settled' : ''}`}>
          <span className="sppd-details-amount-label">Balance due</span>
          <span className={`sppd-details-amount-value ${balanceDueColorClass(order.balanceDue)}`}>
            {formatMoney(order.balanceDue, order.currency)}
          </span>
        </article>
      </section>

      <div className="sppd-details-grid">
        <section className="sppd-card sppd-details-panel">
          <h2 className="sppd-details-panel-title">Order details</h2>
          <div className="sppd-info-grid">
            <InfoItem label="Currency" value={order.currency || '-'} />
            <InfoItem label="PO date" value={formatDate(order.createdAt)} />
            <InfoItem label="Supplier" value={order.payeeName || '-'} />
            <InfoItem label="Workflow status" value={order.status || '-'} />
            {order.paidByName && (
              <InfoItem label="Last paid by" value={order.paidByName} />
            )}
            {order.description && (
              <InfoItem
                label="Description"
                value={order.description}
                className="sppd-info-item--full"
              />
            )}
          </div>
        </section>

        <section className="sppd-card sppd-details-panel sppd-details-panel--attachments">
          <div className="sppd-details-panel-head">
            <h2 className="sppd-details-panel-title">Attachments</h2>
            <span className="sppd-details-panel-count">{attachments.length}</span>
          </div>
          {attachments.length === 0 ? (
            <p className="sppd-details-empty">No attachments uploaded for this purchase order.</p>
          ) : (
            <ul className="sppd-attachments-list">
              {attachments.map((attachment: PurchaseOrderAttachment) => (
                <li key={`${attachment.kind}-${attachment.id}-${attachment.name}`} className="sppd-attachment-item">
                  <div className="sppd-attachment-main">
                    <span className="sppd-attachment-icon" aria-hidden="true">
                      <FileText className="w-4 h-4" />
                    </span>
                    <div className="sppd-attachment-copy">
                      <span className="sppd-attachment-name">{attachment.name}</span>
                      <span className="sppd-attachment-meta">
                        {attachment.fileType || attachment.kind}
                        {attachment.fileSize > 0 ? ` · ${formatFileSize(attachment.fileSize)}` : ''}
                        {attachment.createdAt ? ` · ${formatDate(attachment.createdAt)}` : ''}
                      </span>
                    </div>
                  </div>
                  {attachment.url && (
                    <a
                      href={attachment.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="sppd-attachment-open"
                    >
                      Open
                      <ExternalLink className="w-3.5 h-3.5" aria-hidden="true" />
                    </a>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      <section className="sppd-card sppd-details-panel sppd-details-panel--payments">
        <div className="sppd-details-panel-head">
          <h2 className="sppd-details-panel-title">Payments</h2>
          <span className="sppd-details-panel-count">{payments.length}</span>
        </div>
        {payments.length === 0 ? (
          <p className="sppd-details-empty">No payments recorded yet.</p>
        ) : (
          <div className="sppd-payments-table-wrap">
            <table className="sppd-payments-table">
              <thead>
                <tr>
                  <th>Payment</th>
                  <th>Date</th>
                  <th>Amount</th>
                  <th>Method</th>
                  <th>Account</th>
                  <th className="sppd-payments-table-actions">Proof</th>
                </tr>
              </thead>
              <tbody>
                {payments.map((payment: PurchaseOrderPayment) => (
                  <tr key={payment.id}>
                    <td>
                      <span className="sppd-payment-number">{payment.paymentNumber || '-'}</span>
                      {payment.paidByName && (
                        <span className="sppd-payment-sub">{payment.paidByName}</span>
                      )}
                    </td>
                    <td>{formatDate(payment.paymentDate)}</td>
                    <td className="sppd-amt sppd-amt-paid">{formatMoney(payment.amount, payment.currency)}</td>
                    <td>{payment.paymentMethod || '-'}</td>
                    <td>{payment.accountName || '-'}</td>
                    <td className="sppd-payments-table-actions">
                      {payment.proofUrl ? (
                        <a
                          href={payment.proofUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="sppd-action-link"
                        >
                          View
                        </a>
                      ) : (
                        <span className="sppd-details-empty">-</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
