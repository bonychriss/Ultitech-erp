import { useEffect, useState } from 'react';
import { ArrowLeft, ExternalLink, Loader2 } from 'lucide-react';
import { fetchInit, fetchPurchaseOrderDetails } from '../api';
import PayPurchaseModal from '../components/PayPurchaseModal';
import PaymentDetailsContent from '../components/PaymentDetailsContent';
import { deskListUrl } from '../navigation';
import type { DeskInit, PurchaseOrderDetails } from '../types';
import {
  formatDate,
  formatPaymentStatus,
  isPaidStatus,
  paymentStatusBadgeClass,
} from '../utils/format';

interface PoViewPageProps {
  poId: number;
}

export default function PoViewPage({ poId }: PoViewPageProps) {
  const [init, setInit] = useState<DeskInit | null>(null);
  const [details, setDetails] = useState<PurchaseOrderDetails | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [flash, setFlash] = useState('');
  const [payOpen, setPayOpen] = useState(false);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');
      try {
        const [initData, detailsData] = await Promise.all([
          fetchInit(),
          fetchPurchaseOrderDetails(poId),
        ]);
        if (!cancelled) {
          setInit(initData);
          setDetails(detailsData);
        }
      } catch (err) {
        if (!cancelled) {
          setDetails(null);
          setError(err instanceof Error ? err.message : 'Failed to load purchase order.');
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    load();
    return () => {
      cancelled = true;
    };
  }, [poId]);

  async function handlePaySuccess(message: string) {
    setPayOpen(false);
    setFlash(message);
    try {
      const refreshed = await fetchPurchaseOrderDetails(poId);
      setDetails(refreshed);
    } catch {
      // Keep the existing details if refresh fails after payment.
    }
  }

  if (loading) {
    return (
      <div className="sppd-page sppd-details-page sppd-boot-loading" role="status" aria-live="polite">
        <Loader2 className="sppd-boot-spinner" aria-hidden="true" />
        <span>Loading purchase order...</span>
      </div>
    );
  }

  if (error || !details) {
    return (
      <div className="sppd-page sppd-details-page">
        <div className="sppd-details-topbar">
          <a href={deskListUrl()} className="sppd-details-back">
            <ArrowLeft className="w-4 h-4" aria-hidden="true" />
            Back to payment desk
          </a>
        </div>
        <div className="sppd-boot-error" role="alert">
          <strong>Could not open purchase order</strong>
          <p>{error || 'Purchase order details were not returned.'}</p>
        </div>
      </div>
    );
  }

  const { order } = details;
  const canPay = !isPaidStatus(order.paymentStatus);

  return (
    <div className="sppd-page sppd-details-page">
      <div className="sppd-details-topbar">
        <a href={deskListUrl()} className="sppd-details-back">
          <ArrowLeft className="w-4 h-4" aria-hidden="true" />
          Back to payment desk
        </a>
        <div className="sppd-details-header-actions">
          {order.editUrl?.trim() && !isPaidStatus(order.paymentStatus) && order.balanceDue > 0.009 && (
            <a
              href={order.editUrl}
              className="sppd-btn sppd-btn-secondary sppd-details-action-btn"
            >
              Edit purchase order
            </a>
          )}
          <a
            href={order.viewUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="sppd-btn sppd-btn-secondary sppd-details-action-btn"
          >
            Full PO document
            <ExternalLink className="w-3.5 h-3.5" aria-hidden="true" />
          </a>
          {canPay && (
            <button
              type="button"
              className="sppd-btn sppd-btn-pay sppd-details-action-btn"
              onClick={() => setPayOpen(true)}
            >
              Pay purchase
            </button>
          )}
        </div>
      </div>

      <section className="sppd-details-hero">
        <p className="sppd-details-eyebrow">Purchase order</p>
        <h1 className="sppd-details-title">{order.poNumber}</h1>
        <p className="sppd-details-subtitle">{order.payeeName || 'Supplier'}</p>
        <div className="sppd-details-meta">
          <span className={`sppd-badge ${paymentStatusBadgeClass(order.paymentStatus)}`}>
            {formatPaymentStatus(order.paymentStatus)}
          </span>
          <span className="sppd-details-meta-item">{formatDate(order.createdAt)}</span>
          {order.status && (
            <span className="sppd-details-meta-item sppd-details-meta-item--muted">
              {order.status}
            </span>
          )}
        </div>
      </section>

      {flash && (
        <div className="sppd-flash sppd-flash--success" role="status">
          {flash}
        </div>
      )}

      <PaymentDetailsContent details={details} />

      {payOpen && init && (
        <PayPurchaseModal
          order={order}
          accounts={init.accounts}
          paymentMethods={init.paymentMethods}
          onClose={() => setPayOpen(false)}
          onSuccess={handlePaySuccess}
        />
      )}
    </div>
  );
}
