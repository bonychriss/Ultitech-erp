export interface Product {
  id: string;
  sku: string;
  name: string;
  category: string;
  categoryId?: number;
  price: number;
  cost: number;
  stock: number;
  minStock: number;
  unit: string;
  description: string;
  createdAt: string;
  imageUrl?: string;
}

export interface Category {
  id: string;
  name: string;
  description: string;
}

export interface Warehouse {
  id: number;
  code: string;
  name: string;
  address: string;
  isActive: boolean;
}

export interface StoreConfig {
  currency: string;
  currencySymbol: string;
  showCost: boolean;
  companyName: string;
  companyLogoUrl?: string;
  manageWarehousesUrl: string;
  canManageProducts: boolean;
  manageProductsUrl: string;
}

export interface StockMovement {
  id: string;
  productId: string;
  productName: string;
  productSku: string;
  categoryName: string;
  movementType: 'in' | 'out' | 'adjustment';
  quantity: number;
  referenceType: string;
  referenceId: string;
  notes: string;
  status?: string;
  createdAt: string;
  imageUrl?: string;
}

export interface LabelCategory {
  id: string;
  name: string;
}

export interface LabelProduct {
  id: string;
  productCode: string;
  name: string;
  categoryName: string;
  imageUrl: string;
  labelPlaced: boolean;
}

export interface LabelPerPageOption {
  value: number;
  label: string;
}

export interface LabelsInitData {
  categories: LabelCategory[];
  placedCount: number;
  perPageOptions: LabelPerPageOption[];
  labelDownloadUrl: string;
  labelStarUrl: string;
}

export interface MovementStats {
  totalIn: number;
  totalOut: number;
  netMovement: number;
}

export type StockDirection = 'in' | 'out';

export interface StoreAttachment {
  id: string;
  name: string;
  url: string;
  kind?: string;
}

export interface PendingReceipt {
  id: string;
  warehouseId: number;
  productId: string;
  productName: string;
  productSku: string;
  poId: string;
  poReference: string;
  qtyExpected: number;
  qtyOriginalExpected?: number;
  qtyPriorReceived?: number;
  procuredNotes: string;
  procuredAt: string;
  attachments?: StoreAttachment[];
  poAttachments?: StoreAttachment[];
}

export interface PendingInvoice {
  id: string;
  invoiceNumber: string;
  customerName: string;
  customerPhone: string;
  invoiceDate: string;
  totalAmount: number;
  invoiceStatus: string;
  dispatchStatus: 'awaiting_release' | 'released';
  lineCount: number;
  deliveryNumber?: string;
  viewInvoiceUrl?: string;
  salespersonName?: string;
}

export interface InvoiceLine {
  productId: string;
  productName: string;
  productSku: string;
  qtyInvoiced: number;
  currentStock: number;
  unit: string;
  imageUrl?: string;
}

export interface PurchaseOrderSummary {
  id: string;
  poNumber: string;
  status: string;
  receiveStatus?: 'Pending' | 'Partially received' | 'Received' | string;
  purchaseType: string;
  supplierName: string;
  createdAt: string;
  orderedQty?: number;
  receivedQty?: number;
  remainingQty: number;
  lineCount: number;
  source: 'stocks' | 'legacy';
}

export interface PurchaseOrderAttachment {
  id: string;
  name: string;
  url: string;
  kind?: string;
}

export interface PurchaseOrderLine {
  lineId: string;
  productId: string;
  productName: string;
  productSku: string;
  qtyOrdered: number;
  qtyReceived: number;
  qtyRemaining: number;
  receiveStatus?: 'Pending' | 'Partially received' | 'Received' | string;
  unitCost: number;
  imageUrl?: string;
}
