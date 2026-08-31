import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Loader2, X } from 'lucide-react';
import { fetchPurchaseOrderDetails } from '../api';
import { poViewUrl } from '../navigation';
import type { PurchaseOrderPayment, PurchaseOrderRow } from '../types';
import {
  balanceDueColorClass,
  formatDate,
  formatMoney,
  formatPaymentStatus,
  isPaidStatus,
  paymentStatusBadgeClass,
} from '../utils/format';

interface PoQuickViewModalProps {
  order: PurchaseOrderRow;
  onClose: () => void;
  onPay: () => void;
}

export default function PoQuickViewModal({
  order,
  onClose,
  onPay,
}: PoQuickViewModalProps) {
  const canPay = !isPaidStatus(order.paymentStatus);
  const [payments, setPayments] = useState<PurchaseOrderPayment[]>([]);
  const [paymentsLoading, setPaymentsLoading] = useState(true);
  const [paymentsError, setPaymentsError] = useState('');

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose();
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [onClose]);

  useEffect(() => {
    let cancelled = false;

    async function loadPayments() {
      setPaymentsLoading(true);
      setPaymentsError('');
      try {
        const details = await fetchPurchaseOrderDetails(order.id);
        if (!cancelled) {
          setPayments(details.payments);
        }
      } catch (err) {
        if (!cancelled) {
          setPayments([]);
          setPaymentsError(err instanceof Error ? err.message : 'Failed to load payments.');
        }
      } finally {
        if (!cancelled) {
          setPaymentsLoading(false);
        }
      }
    }

    loadPayments();
    return () => {
      cancelled = true;
    };
  }, [order.id]);

  return createPortal(
    <div className="sppd-modal-backdrop" onClick={onClose} role="presentation">
      <div
        className="sppd-modal sppd-po-quick-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sppd-po-quick-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="sppd-po-quick-head">
          <div className="sppd-po-quick-head-main">
            <h2 id="sppd-po-quick-title" className="sppd-po-quick-title">{order.poNumber}</h2>
            <p className="sppd-po-quick-subtitle">{order.payeeName || 'Supplier'}</p>
            <div className="sppd-po-quick-meta">
              <span className={`sppd-badge ${paymentStatusBadgeClass(order.paymentStatus)}`}>
                {formatPaymentStatus(order.paymentStatus)}
              </span>
              <span className="sppd-po-quick-date">{formatDate(order.createdAt)}</span>
            </div>
          </div>
          <button type="button" className="sppd-po-quick-close" onClick={onClose} aria-label="Close">
            <X className="w-5 h-5" />
          </button>
        </div>

        <div className="sppd-po-quick-body">
          <dl className="sppd-po-quick-amounts">
            <div>
              <dt>PO total</dt>
              <dd>{formatMoney(order.amountToPay, order.currency)}</dd>
            </div>
            <div>
              <dt>Paid</dt>
              <dd className="sppd-amt-paid">{formatMoney(order.amountPaid, order.currency)}</dd>
            </div>
            <div>
              <dt>Balance due</dt>
              <dd className={balanceDueColorClass(order.balanceDue)}>
                {formatMoney(order.balanceDue, order.currency)}
              </dd>
            </div>
          </dl>

          <section className="sppd-po-quick-payments" aria-label="Payments">
            <h3 className="sppd-po-quick-payments-title">Payments</h3>
            {paymentsLoading ? (
              <div className="sppd-po-quick-payments-loading" role="status">
                <Loader2 className="sppd-boot-spinner" aria-hidden="true" />
                <span>Loading...</span>
              </div>
            ) : paymentsError ? (
              <p className="sppd-po-quick-payments-empty">{paymentsError}</p>
            ) : payments.length === 0 ? (
              <p className="sppd-po-quick-payments-empty">No payments yet.</p>
            ) : (
              <ul className="sppd-po-quick-payments-list">
                {payments.map((payment) => (
                  <li key={payment.id} className="sppd-po-quick-payment">
                    <span className="sppd-po-quick-payment-line">
                      <strong>{payment.paymentNumber || '-'}</strong>
                      <span>{formatDate(payment.paymentDate)}</span>
                      <span className="sppd-amt-paid">{formatMoney(payment.amount, payment.currency)}</span>
                      {payment.paymentMethod && <span>{payment.paymentMethod}</span>}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>

        <div className="sppd-po-quick-foot">
          <a
            href={poViewUrl(order.id)}
            className="sppd-btn sppd-btn-secondary sppd-po-quick-link"
          >
            Open PO
          </a>
          {canPay && (
            <button type="button" className="sppd-btn sppd-btn-pay" onClick={onPay}>
              Pay purchase
            </button>
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
}
