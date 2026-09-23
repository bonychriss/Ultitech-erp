import React from 'react';
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

/**
 * Warehouse receive: open purchase orders from procurement for accept into stock.
 */
export default function StoreReceiveForm({
  warehouseId,
  products,
  canReceivePurchaseOrders = true,
  confirmPoToStock = true,
  onReceived,
}: StoreReceiveFormProps) {
  return (
    <div className="sms-form-shell sms-form-shell--excel">
      {canReceivePurchaseOrders ? (
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
