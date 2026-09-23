import React, { useState } from 'react';
import { ClipboardCheck, Truck } from 'lucide-react';
import PurchaseOrderReceive from './PurchaseOrderReceive';
import VerifyReceipts from './VerifyReceipts';
import type { Product } from '../types';

interface StoreReceiveFormProps {
  warehouseId: number;
  products: Product[];
  canReceivePurchaseOrders?: boolean;
  confirmPoToStock?: boolean;
  onReceived: () => Promise<void>;
}

type ReceiveMode = 'purchase' | 'verify';

/**
 * Warehouse receive:
 * - Purchase orders: open POs from procurement for the store keeper to accept into stock
 * - Pending confirmations: leftover pending receipts (e.g. procurement-only delivery records)
 */
export default function StoreReceiveForm({
  warehouseId,
  products,
  canReceivePurchaseOrders = true,
  confirmPoToStock = true,
  onReceived,
}: StoreReceiveFormProps) {
  const [mode, setMode] = useState<ReceiveMode>(canReceivePurchaseOrders ? 'purchase' : 'verify');

  return (
    <div className="sms-form-shell sms-form-shell--excel">
      {canReceivePurchaseOrders && (
        <div className="sms-form-mode-toggle" role="tablist" aria-label="Receive mode">
          <button
            type="button"
            role="tab"
            aria-selected={mode === 'purchase'}
            className={`sms-desk-btn sms-btn-rounded${mode === 'purchase' ? ' sms-desk-btn-primary' : ' sms-desk-btn-secondary'}`}
            onClick={() => setMode('purchase')}
          >
            <Truck className="w-4 h-4" />
            <span>Purchase orders</span>
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={mode === 'verify'}
            className={`sms-desk-btn sms-btn-rounded${mode === 'verify' ? ' sms-desk-btn-primary' : ' sms-desk-btn-secondary'}`}
            onClick={() => setMode('verify')}
          >
            <ClipboardCheck className="w-4 h-4" />
            <span>Pending confirmations</span>
          </button>
        </div>
      )}

      {mode === 'purchase' && canReceivePurchaseOrders ? (
        <PurchaseOrderReceive
          warehouseId={warehouseId}
          confirmToStock={confirmPoToStock}
          onReceived={onReceived}
        />
      ) : (
        <VerifyReceipts warehouseId={warehouseId} products={products} onVerified={onReceived} />
      )}
    </div>
  );
}
