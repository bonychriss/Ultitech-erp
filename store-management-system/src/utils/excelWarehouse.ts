import type { PendingReceipt, Product, StockMovement } from '../types';

type SheetRow = Record<string, string | number>;

function normalizeKey(key: string): string {
  return key.trim().toLowerCase().replace(/[\s-]+/g, '_');
}

function cellValue(value: unknown): string {
  if (value === null || value === undefined) return '';
  return String(value).trim();
}

export async function loadXlsx() {
  const mod = await import('xlsx');
  return mod.default ?? mod;
}

export async function parseExcelFile(file: File): Promise<SheetRow[]> {
  const XLSX = await loadXlsx();
  const buffer = await file.arrayBuffer();
  const workbook = XLSX.read(buffer, { type: 'array' });
  const sheetName = workbook.SheetNames[0];
  if (!sheetName) return [];
  const sheet = workbook.Sheets[sheetName];
  const raw = XLSX.utils.sheet_to_json<Record<string, unknown>>(sheet, { defval: '' });
  return raw.map((row) => {
    const mapped: SheetRow = {};
    for (const [key, value] of Object.entries(row)) {
      mapped[normalizeKey(key)] = typeof value === 'number' ? value : cellValue(value);
    }
    return mapped;
  });
}

async function writeWorkbook(filename: string, rows: Record<string, string | number>[], sheetName = 'Sheet1') {
  const XLSX = await loadXlsx();
  const worksheet = XLSX.utils.json_to_sheet(rows);
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, worksheet, sheetName);
  XLSX.writeFile(workbook, filename);
}

function stamp(name: string): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${name}-${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}.xlsx`;
}

export async function exportMovementsExcel(movements: StockMovement[], warehouseName: string) {
  const rows = movements.map((m) => ({
    date: m.createdAt,
    type: m.movementType === 'in' ? 'Incoming' : 'Outgoing',
    product_sku: m.productSku || '',
    product_name: m.productName || '',
    quantity: m.quantity,
    status: m.status || '',
    notes: m.notes || '',
    reference: m.referenceType || '',
  }));
  await writeWorkbook(stamp(`warehouse-movements-${warehouseName.replace(/\s+/g, '-')}`), rows, 'Movements');
}

export async function exportIncomingExcel(receipts: PendingReceipt[]) {
  const rows = receipts.map((r) => ({
    receipt_id: r.id,
    product_sku: r.productSku,
    product_name: r.productName,
    po_reference: r.poReference,
    qty_expected: r.qtyExpected,
    qty_verified: r.qtyExpected,
    notes: '',
  }));
  await writeWorkbook(stamp('incoming-verify'), rows, 'Incoming');
}

export async function exportIncomingTemplate() {
  await writeWorkbook('incoming-template.xlsx', [
    {
      receipt_id: '123',
      product_sku: 'SKU-001',
      product_name: 'Example product',
      po_reference: 'PO-2026-001',
      qty_expected: 10,
      qty_verified: 10,
      notes: 'Optional reason if qty_verified is less than expected',
    },
  ], 'Incoming');
}

export async function exportOutgoingTemplate() {
  await writeWorkbook('outgoing-sample-template.xlsx', [
    {
      product_sku: 'SKU-001',
      product_name: 'Example product',
      quantity: 2,
      reason: 'Sample / promotional giveaway',
      notes: 'Optional notes',
    },
  ], 'Outgoing');
}

export interface IncomingImportRow {
  receiptId: string;
  qtyVerified: number;
  notes: string;
}

export function parseIncomingRows(rows: SheetRow[], receipts: PendingReceipt[]): {
  rows: IncomingImportRow[];
  errors: string[];
} {
  const receiptMap = new Map(receipts.map((r) => [r.id, r]));
  const skuMap = new Map(receipts.map((r) => [r.productSku.toLowerCase(), r]));
  const parsed: IncomingImportRow[] = [];
  const errors: string[] = [];

  rows.forEach((row, index) => {
    const line = index + 2;
    let receipt = receiptMap.get(cellValue(row.receipt_id));
    if (!receipt) {
      const sku = cellValue(row.product_sku).toLowerCase();
      if (sku) receipt = skuMap.get(sku);
    }
    if (!receipt) {
      errors.push(`Row ${line}: receipt not found (use receipt_id or product_sku from pending list).`);
      return;
    }

    const qtyRaw = row.qty_verified ?? row.quantity ?? row.qty ?? receipt.qtyExpected;
    const qtyVerified = Number(qtyRaw);
    if (Number.isNaN(qtyVerified) || qtyVerified < 0) {
      errors.push(`Row ${line}: invalid qty_verified for ${receipt.productSku}.`);
      return;
    }

    parsed.push({
      receiptId: receipt.id,
      qtyVerified,
      notes: cellValue(row.notes),
    });
  });

  return { rows: parsed, errors };
}

export interface OutgoingImportRow {
  productId: string;
  productSku: string;
  productName: string;
  quantity: string;
  reason: string;
  notes: string;
}

export function parseOutgoingRows(rows: SheetRow[], products: Product[]): {
  rows: OutgoingImportRow[];
  errors: string[];
} {
  const bySku = new Map(products.map((p) => [p.sku.toLowerCase(), p]));
  const byId = new Map(products.map((p) => [p.id, p]));
  const parsed: OutgoingImportRow[] = [];
  const errors: string[] = [];

  rows.forEach((row, index) => {
    const line = index + 2;
    const sku = cellValue(row.product_sku || row.sku).toLowerCase();
    const productId = cellValue(row.product_id);
    let product = productId ? byId.get(productId) : undefined;
    if (!product && sku) product = bySku.get(sku);
    if (!product) {
      errors.push(`Row ${line}: product not found (use product_sku).`);
      return;
    }

    const qtyRaw = row.quantity ?? row.qty ?? row.qty_out;
    const quantity = cellValue(qtyRaw);
    const qtyNum = Number(quantity);
    if (!quantity || Number.isNaN(qtyNum) || qtyNum <= 0) {
      errors.push(`Row ${line}: invalid quantity for ${product.sku}.`);
      return;
    }

    parsed.push({
      productId: product.id,
      productSku: product.sku,
      productName: product.name,
      quantity: String(qtyNum),
      reason: cellValue(row.reason) || 'Sample / promotional giveaway',
      notes: cellValue(row.notes),
    });
  });

  return { rows: parsed, errors };
}
