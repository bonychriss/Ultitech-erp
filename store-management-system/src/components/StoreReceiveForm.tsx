import React, { useState } from 'react';
import { ClipboardCheck, Truck } from 'lucide-react';
import PurchaseOrderReceive from './PurchaseOrderReceive';
import VerifyReceipts from './VerifyReceipts';
import type { Product } from '../types';

interface StoreReceiveFormProps {
  warehouseId: number;
  products: Product[];
  canReceivePurchaseOrders?: boolean;
  onReceived: () => Promise<void>;
}

type ReceiveMode = 'purchase' | 'verify';

/**
 * Two-step receive:
 * - Procurement records PO delivery ? pending receipt (no stock yet)
 * - Store manager confirms ? on-hand stock increases
 */
export default function StoreReceiveForm({
  warehouseId,
  products,
  canReceivePurchaseOrders = false,
  onReceived,
}: StoreReceiveFormProps) {
  const [mode, setMode] = useState<ReceiveMode>('verify');

  return (
    <div className="sms-form-shell sms-form-shell--excel">
      {canReceivePurchaseOrders && (
        <div className="sms-form-mode-toggle" role="tablist" aria-label="Receive mode">
          <button
            type="button"
            role="tab"
            aria-selected={mode === 'verify'}
            className={`sms-desk-btn sms-btn-rounded${mode === 'verify' ? ' sms-desk-btn-primary' : ' sms-desk-btn-secondary'}`}
            onClick={() => setMode('verify')}
          >
            <ClipboardCheck className="w-4 h-4" />
            <span>Confirm into stock</span>
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={mode === 'purchase'}
            className={`sms-desk-btn sms-btn-rounded${mode === 'purchase' ? ' sms-desk-btn-primary' : ' sms-desk-btn-secondary'}`}
            onClick={() => setMode('purchase')}
          >
            <Truck className="w-4 h-4" />
            <span>Record delivery</span>
          </button>
        </div>
      )}

      {mode === 'purchase' && canReceivePurchaseOrders ? (
        <div className="sms-form-card sms-form-card--flush">
          <PurchaseOrderReceive warehouseId={warehouseId} onReceived={onReceived} />
        </div>
      ) : (
        <VerifyReceipts warehouseId={warehouseId} products={products} onVerified={onReceived} />
      )}
    </div>
  );
}
