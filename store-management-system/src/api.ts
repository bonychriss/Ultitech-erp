import type {
  Category,
  LabelPerPageOption,
  LabelProduct,
  LabelsInitData,
  MovementStats,
  PendingReceipt,
  PendingInvoice,
  Product,
  PurchaseOrderAttachment,
  PurchaseOrderLine,
  PurchaseOrderSummary,
  InvoiceLine,
  StockDirection,
  StockMovement,
  StoreConfig,
  Warehouse,
} from './types';

declare global {
  interface Window {
    __STORE_MGMT_CFG__?: {
      apiUrl?: string;
      page?: string;
      labelDownloadUrl?: string;
      labelStarUrl?: string;
    };
  }
}

const API_BASE =
  (typeof window !== 'undefined' && window.__STORE_MGMT_CFG__?.apiUrl) ||
  (import.meta.env.DEV ? '/api/index.php' : './api/index.php');

async function request<T>(action: string, options: RequestInit & { params?: Record<string, string> } = {}): Promise<T> {
  const { params, ...fetchOptions } = options;
  let url = `${API_BASE}?action=${encodeURIComponent(action)}`;

  if (params) {
    for (const [key, value] of Object.entries(params)) {
      url += `&${encodeURIComponent(key)}=${encodeURIComponent(value)}`;
    }
  }

  const headers: HeadersInit = {
  ...(fetchOptions.headers || {}),
  };

  let body = fetchOptions.body;
  if (body && typeof body === 'object' && !(body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(body);
  }

  const response = await fetch(url, {
    ...fetchOptions,
    headers,
    body: body as BodyInit | undefined,
    credentials: 'same-origin',
  });

  const data = await response.json();
  if (!response.ok || data.success === false) {
    throw new Error(data.error || `Request failed (${response.status})`);
  }

  return data as T;
}

export async function fetchInit(): Promise<{
  warehouses: Warehouse[];
  categories: Category[];
  config: StoreConfig;
}> {
  const data = await request<{
    warehouses: Warehouse[];
    categories: Category[];
    config: StoreConfig;
  }>('init');
  return {
    warehouses: data.warehouses,
    categories: data.categories,
    config: data.config,
  };
}

export async function fetchProducts(warehouseId: number): Promise<Product[]> {
  const data = await request<{ products: Product[] }>('products', {
    params: { warehouse_id: String(warehouseId) },
  });
  return data.products;
}

export async function addProduct(warehouseId: number, product: Omit<Product, 'id' | 'createdAt'>): Promise<Product> {
  const data = await request<{ product: Product }>('product_add', {
    method: 'POST',
    body: JSON.stringify({ action: 'product_add', warehouse_id: warehouseId, ...product }),
  });
  return data.product;
}

export async function updateProduct(warehouseId: number, product: Product): Promise<Product> {
  const data = await request<{ product: Product }>('product_update', {
    method: 'POST',
    body: JSON.stringify({ action: 'product_update', warehouse_id: warehouseId, ...product }),
  });
  return data.product;
}

export async function deleteProduct(productId: string): Promise<void> {
  await request('product_delete', {
    method: 'POST',
    body: JSON.stringify({ action: 'product_delete', id: productId }),
  });
}

export async function adjustStock(warehouseId: number, productId: string, change: number): Promise<number> {
  const data = await request<{ stock: number }>('stock_adjust', {
    method: 'POST',
    body: JSON.stringify({
      action: 'stock_adjust',
      warehouse_id: warehouseId,
      product_id: productId,
      change,
    }),
  });
  return data.stock;
}

export async function addCategory(category: Omit<Category, 'id'>): Promise<Category> {
  const data = await request<{ category: Category }>('category_add', {
    method: 'POST',
    body: JSON.stringify({ action: 'category_add', ...category }),
  });
  return data.category;
}

export async function deleteCategory(categoryId: string): Promise<void> {
  await request('category_delete', {
    method: 'POST',
    body: JSON.stringify({ action: 'category_delete', id: categoryId }),
  });
}

export interface MovementFilters {
  productId?: string;
  type?: string;
  search?: string;
  startDate?: string;
  endDate?: string;
}

export async function fetchMovements(
  warehouseId: number,
  filters: MovementFilters = {}
): Promise<{ movements: StockMovement[]; stats: MovementStats }> {
  const params: Record<string, string> = {
    warehouse_id: String(warehouseId),
  };
  if (filters.productId) params.product_id = filters.productId;
  if (filters.type) params.type = filters.type;
  if (filters.search) params.search = filters.search;
  if (filters.startDate) params.start_date = filters.startDate;
  if (filters.endDate) params.end_date = filters.endDate;

  const data = await request<{ movements: StockMovement[]; stats: MovementStats }>('movements', { params });
  return { movements: data.movements, stats: data.stats };
}

export async function recordStockMovement(
  warehouseId: number,
  payload: {
    productId: string;
    direction: StockDirection;
    quantity: number;
    reason: string;
    notes?: string;
  }
): Promise<{ stock: number }> {
  const data = await request<{ stock: number }>('stock_movement', {
    method: 'POST',
    body: JSON.stringify({
      action: 'stock_movement',
      warehouse_id: warehouseId,
      product_id: payload.productId,
      direction: payload.direction,
      quantity: payload.quantity,
      reason: payload.reason,
      notes: payload.notes ?? '',
    }),
  });
  return { stock: data.stock };
}

export async function recordSampleStockOut(
  warehouseId: number,
  payload: {
    items: Record<string, number>;
    reason: string;
    notes?: string;
    issuerName: string;
    receipts: File[];
  }
): Promise<{ stock: number; message?: string }> {
  const formData = new FormData();
  formData.append('action', 'sample_stock_out');
  formData.append('warehouse_id', String(warehouseId));
  formData.append('items', JSON.stringify(payload.items));
  formData.append('reason', payload.reason);
  formData.append('notes', payload.notes ?? '');
  formData.append('issuer_name', payload.issuerName);
  payload.receipts.forEach((file, index) => {
    formData.append(index === 0 ? 'supporting_document' : `extra_receipt_${index}`, file);
  });

  const data = await request<{ stock: number; message?: string }>('sample_stock_out', {
    method: 'POST',
    body: formData,
  });
  return { stock: data.stock, message: data.message };
}

export async function fetchPendingReceipts(warehouseId: number): Promise<PendingReceipt[]> {
  const data = await request<{ receipts: PendingReceipt[] }>('pending_receipts', {
    params: { warehouse_id: String(warehouseId) },
  });
  return data.receipts;
}

export async function verifyReceipt(
  warehouseId: number,
  payload: { receiptId: string; qtyVerified: number; notes?: string }
): Promise<{ message: string }> {
  const data = await request<{ message: string }>('verify_receipt', {
    method: 'POST',
    body: JSON.stringify({
      action: 'verify_receipt',
      warehouse_id: warehouseId,
      receipt_id: payload.receiptId,
      qty_verified: payload.qtyVerified,
      notes: payload.notes ?? '',
    }),
  });
  return { message: data.message };
}

export async function updatePendingReceipt(
  warehouseId: number,
  payload: { receiptId: string; qtyExpected: number; poReference?: string }
): Promise<{ message: string }> {
  const data = await request<{ message: string }>('update_pending_receipt', {
    method: 'POST',
    body: JSON.stringify({
      action: 'update_pending_receipt',
      warehouse_id: warehouseId,
      receipt_id: payload.receiptId,
      qty_expected: payload.qtyExpected,
      po_reference: payload.poReference ?? '',
    }),
  });
  return { message: data.message };
}

export async function confirmManualIncoming(
  warehouseId: number,
  payload: {
    productId: string;
    qtyExpected: number;
    qtyVerified: number;
    poReference?: string;
    notes?: string;
  }
): Promise<{ message: string }> {
  const data = await request<{ message: string }>('manual_incoming_confirm', {
    method: 'POST',
    body: JSON.stringify({
      action: 'manual_incoming_confirm',
      warehouse_id: warehouseId,
      product_id: payload.productId,
      qty_expected: payload.qtyExpected,
      qty_verified: payload.qtyVerified,
      po_reference: payload.poReference ?? '',
      notes: payload.notes ?? '',
    }),
  });
  return { message: data.message };
}

export async function fetchPurchaseOrders(): Promise<PurchaseOrderSummary[]> {
  const data = await request<{ orders: PurchaseOrderSummary[] }>('purchase_orders');
  return data.orders;
}

export async function fetchPurchaseOrder(
  poId: string,
  source: string
): Promise<{ order: PurchaseOrderSummary; lines: PurchaseOrderLine[]; attachments: PurchaseOrderAttachment[] }> {
  const data = await request<{
    order: PurchaseOrderSummary;
    lines: PurchaseOrderLine[];
    attachments?: PurchaseOrderAttachment[];
  }>('purchase_order', {
    params: { po_id: poId, source },
  });
  return { order: data.order, lines: data.lines, attachments: data.attachments ?? [] };
}

export async function receivePurchaseOrder(
  warehouseId: number,
  payload: {
    poId: string;
    source: string;
    receiveQty: Record<string, number>;
    notes?: string;
    attachments?: File[];
  }
): Promise<{ message: string }> {
  const formData = new FormData();
  formData.append('action', 'purchase_order_receive');
  formData.append('warehouse_id', String(warehouseId));
  formData.append('po_id', payload.poId);
  formData.append('source', payload.source);
  formData.append('receive_qty', JSON.stringify(payload.receiveQty));
  formData.append('notes', payload.notes ?? '');

  const files = payload.attachments ?? [];
  files.forEach((file, index) => {
    formData.append(index === 0 ? 'delivery_attachment' : `extra_receipt_${index}`, file);
  });

  const data = await request<{ message: string }>('purchase_order_receive', {
    method: 'POST',
    body: formData,
  });
  return { message: data.message };
}

export async function fetchPendingInvoices(
  warehouseId: number,
  filter: 'pending' | 'released' | 'all' = 'pending'
): Promise<PendingInvoice[]> {
  const data = await request<{ invoices: PendingInvoice[] }>('pending_invoices', {
    params: { warehouse_id: String(warehouseId), filter },
  });
  return data.invoices;
}

export async function fetchInvoiceDetail(
  warehouseId: number,
  invoiceId: string
): Promise<{ invoice: PendingInvoice; lines: InvoiceLine[] }> {
  const data = await request<{ invoice: PendingInvoice; lines: InvoiceLine[] }>('invoice_detail', {
    params: { warehouse_id: String(warehouseId), invoice_id: invoiceId },
  });
  return { invoice: data.invoice, lines: data.lines };
}

export async function dispatchInvoice(
  warehouseId: number,
  payload: {
    invoiceId: string;
    items: Record<string, number>;
    notes?: string;
    supportingDocument?: File | null;
    invoiceDocument?: File | null;
    issuerName?: string;
    signatureDataUrl?: string;
    extraReceipts?: File[];
  }
): Promise<{ message: string; deliveryNumber?: string }> {
  const formData = new FormData();
  formData.append('action', 'invoice_dispatch');
  formData.append('warehouse_id', String(warehouseId));
  formData.append('invoice_id', payload.invoiceId);
  formData.append('items', JSON.stringify(payload.items));
  formData.append('notes', payload.notes ?? '');
  if (payload.supportingDocument) {
    formData.append('supporting_document', payload.supportingDocument);
  }
  if (payload.invoiceDocument) {
    formData.append('invoice_document', payload.invoiceDocument);
  }
  if (payload.issuerName) {
    formData.append('issuer_name', payload.issuerName);
  }
  if (payload.signatureDataUrl) {
    formData.append('issuer_signature', payload.signatureDataUrl);
  }
  (payload.extraReceipts ?? []).forEach((file, index) => {
    formData.append(`extra_receipt_${index}`, file);
  });

  const data = await request<{ message: string; deliveryNumber?: string }>('invoice_dispatch', {
    method: 'POST',
    body: formData,
  });
  return { message: data.message, deliveryNumber: data.deliveryNumber };
}

export async function fetchLabelsInit(): Promise<LabelsInitData> {
  const data = await request<LabelsInitData & { success: boolean }>('labels_init');
  return {
    categories: data.categories,
    placedCount: data.placedCount,
    perPageOptions: data.perPageOptions,
    labelDownloadUrl: data.labelDownloadUrl,
    labelStarUrl: data.labelStarUrl,
  };
}

export async function fetchLabelProducts(filters: {
  search?: string;
  categoryId?: number;
  placed?: 'all' | 'placed' | 'unplaced';
}): Promise<{ products: LabelProduct[]; placedCount: number }> {
  const params: Record<string, string> = {};
  if (filters.search?.trim()) params.search = filters.search.trim();
  if (filters.categoryId && filters.categoryId > 0) params.category_id = String(filters.categoryId);
  if (filters.placed && filters.placed !== 'all') params.placed = filters.placed;

  const data = await request<{ products: LabelProduct[]; placedCount: number; success: boolean }>('labels_products', {
    params,
  });
  return { products: data.products, placedCount: data.placedCount };
}
