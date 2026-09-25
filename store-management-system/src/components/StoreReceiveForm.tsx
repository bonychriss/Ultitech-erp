import React from 'react';
import PurchaseOrderReceive from './PurchaseOrderReceive';
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
  canReceivePurchaseOrders = true,
  confirmPoToStock = true,
  onReceived,
}: StoreReceiveFormProps) {
  return (
    <div className="sms-form-shell sms-form-shell--excel">
      <PurchaseOrderReceive
        warehouseId={warehouseId}
        confirmToStock={confirmPoToStock}
        canSubmit={canReceivePurchaseOrders}
        onReceived={onReceived}
      />
    </div>
  );
}
