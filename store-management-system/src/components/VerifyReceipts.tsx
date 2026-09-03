import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { CheckCircle2, Loader2, Trash2 } from 'lucide-react';
import {
  confirmManualIncoming,
  fetchPendingReceipts,
  updatePendingReceipt,
  verifyReceipt,
} from '../api';
import type { PendingReceipt, Product } from '../types';
import StatusPopup, { type StatusPopupTone } from './StatusPopup';
import ExcelGrid, { type ExcelColumn } from './ExcelGrid';
import ProductPickerCell from './ProductPickerCell';
import PoReferencePickerCell from './PoReferencePickerCell';

interface VerifyReceiptsProps {
  warehouseId: number;
  products: Product[];
  onVerified: () => Promise<void>;
}

interface IncomingGridRow {
  rowId: string;
  receiptId: string | null;
  productId: string;
  productSku: string;
  productName: string;
  poReference: string;
  qtyExpected: string;
  qtyVerified: string;
  notes: string;
  isManual: boolean;
}

type PopupState = {
  title: string;
  message: string;
  tone: StatusPopupTone;
} | null;

let manualRowSeq = 0;

function createManualRow(): IncomingGridRow {
  manualRowSeq += 1;
  return {
    rowId: `manual-${manualRowSeq}`,
    receiptId: null,
    productId: '',
    productSku: '',
    productName: '',
    poReference: '',
    qtyExpected: '',
    qtyVerified: '',
    notes: '',
    isManual: true,
  };
}

function rowHasContent(row: IncomingGridRow): boolean {
  return Boolean(
    row.productSku.trim() ||
    row.poReference.trim() ||
    row.qtyExpected.trim() ||
    row.qtyVerified.trim() ||
    row.notes.trim() ||
    row.productId
  );
}

const TRAILING_EMPTY_ROWS = 20;

function ensureTrailingEmptyRows(
  rows: IncomingGridRow[],
  minTrailing = TRAILING_EMPTY_ROWS
): IncomingGridRow[] {
  if (rows.length === 0) {
    return Array.from({ length: minTrailing }, () => createManualRow());
  }
  let trailing = 0;
  for (let i = rows.length - 1; i >= 0; i -= 1) {
    if (rows[i].isManual && !rowHasContent(rows[i])) trailing += 1;
    else break;
  }
  const toAdd = Math.max(0, minTrailing - trailing);
  if (toAdd === 0) return rows;
  return [...rows, ...Array.from({ length: toAdd }, () => createManualRow())];
}

function matchPendingReceipt(
  row: IncomingGridRow,
  product: Product,
  pendingReceipts: PendingReceipt[]
): PendingReceipt | undefined {
  if (row.receiptId) {
    return pendingReceipts.find((receipt) => receipt.id === row.receiptId);
  }
  const forProduct = pendingReceipts.filter((receipt) => receipt.productId === product.id);
  const po = row.poReference.trim().toLowerCase();
  if (po) {
    return forProduct.find((receipt) => (receipt.poReference || '').trim().toLowerCase() === po);
  }
  return forProduct.length === 1 ? forProduct[0] : undefined;
}

export default function VerifyReceipts({ warehouseId, products, onVerified }: VerifyReceiptsProps) {
  const [gridRows, setGridRows] = useState<IncomingGridRow[]>(() => ensureTrailingEmptyRows([]));
  const [pendingReceipts, setPendingReceipts] = useState<PendingReceipt[]>([]);
  const [confirmingAll, setConfirmingAll] = useState(false);
  const [processingRowId, setProcessingRowId] = useState<string | null>(null);
  const [statusPopup, setStatusPopup] = useState<PopupState>(null);

  const productBySku = useMemo(() => {
    const map = new Map<string, Product>();
    for (const product of products) {
      if (product.sku) map.set(product.sku.toLowerCase(), product);
    }
    return map;
  }, [products]);

  const productById = useMemo(() => new Map(products.map((p) => [p.id, p])), [products]);

  const refreshPendingReceipts = useCallback(async () => {
    try {
      const list = await fetchPendingReceipts(warehouseId);
      setPendingReceipts(list);
    } catch {
      setPendingReceipts([]);
    }
  }, [warehouseId]);

  useEffect(() => {
    setGridRows(ensureTrailingEmptyRows([]));
    void refreshPendingReceipts();
  }, [warehouseId, refreshPendingReceipts]);

  const handleRowsChange = useCallback((rows: IncomingGridRow[]) => {
    setGridRows(ensureTrailingEmptyRows(rows));
  }, []);

  const removeRow = useCallback((rowId: string) => {
    setGridRows((prev) => ensureTrailingEmptyRows(prev.filter((row) => row.rowId !== rowId)));
  }, []);

  const resolveProduct = (row: IncomingGridRow): Product | undefined => {
    if (row.productId) return productById.get(row.productId);
    const sku = row.productSku.trim().toLowerCase();
    return sku ? productBySku.get(sku) : undefined;
  };

  const validateRow = (row: IncomingGridRow): string | null => {
    const product = resolveProduct(row);
    const expected = Number(row.qtyExpected);
    const verified = Number(row.qtyVerified);
    const label = row.productSku || row.productName || 'Row';

    if (!product) {
      return `${label}: product not found (check SKU)`;
    }
    if (Number.isNaN(verified) || verified < 0) {
      return `${label}: invalid verified quantity`;
    }
    if (verified <= 0) {
      return `${label}: verified quantity must be greater than zero`;
    }
    const expectedQty = Number.isNaN(expected) || expected <= 0 ? verified : expected;
    const isShortfall = expectedQty > 0 && verified < expectedQty - 0.0001;
    if (isShortfall && !row.notes.trim()) {
      return `${label}: notes required when verified qty is lower than expected`;
    }
    return null;
  };

  const confirmRow = async (row: IncomingGridRow): Promise<string | null> => {
    const validationError = validateRow(row);
    if (validationError) return validationError;

    const product = resolveProduct(row);
    if (!product) {
      return `${row.productSku || 'Row'}: product not found (check SKU)`;
    }

    const expected = Number(row.qtyExpected);
    const verified = Number(row.qtyVerified);
    const expectedQty = Number.isNaN(expected) || expected <= 0 ? verified : expected;
    const notes = row.notes.trim();

    try {
      const pending = matchPendingReceipt(row, product, pendingReceipts);
      if (pending) {
        await updatePendingReceipt(warehouseId, {
          receiptId: pending.id,
          qtyExpected: expectedQty,
          poReference: row.poReference.trim(),
        });
        await verifyReceipt(warehouseId, {
          receiptId: pending.id,
          qtyVerified: verified,
          notes,
        });
        return null;
      }

      await confirmManualIncoming(warehouseId, {
        productId: product.id,
        qtyExpected: expectedQty,
        qtyVerified: verified,
        poReference: row.poReference.trim(),
        notes,
      });
      return null;
    } catch (err) {
      return `${row.productSku || product.name}: ${err instanceof Error ? err.message : 'failed'}`;
    }
  };

  const handleConfirmOne = async (row: IncomingGridRow) => {
    setProcessingRowId(row.rowId);
    const errMsg = await confirmRow(row);
    if (errMsg) {
      setStatusPopup({ title: 'Confirmation failed', message: errMsg, tone: 'error' });
    } else {
      setStatusPopup({ title: 'Stock confirmed', message: 'Product confirmed into stock.', tone: 'success' });
      removeRow(row.rowId);
      await onVerified();
      await refreshPendingReceipts();
    }
    setProcessingRowId(null);
  };

  const handleConfirmAll = async () => {
    const rowsToConfirm = gridRows.filter((row) => Number(row.qtyVerified) > 0 && resolveProduct(row));
    if (rowsToConfirm.length === 0) {
      setStatusPopup({
        title: 'Nothing to confirm',
        message: 'Enter verified quantities for at least one product row.',
        tone: 'error',
      });
      return;
    }

    setConfirmingAll(true);
    const fail: string[] = [];
    let ok = 0;
    for (const row of rowsToConfirm) {
      const errMsg = await confirmRow(row);
      if (errMsg) fail.push(errMsg);
      else ok += 1;
    }
    await onVerified();
    await refreshPendingReceipts();
    setGridRows(ensureTrailingEmptyRows([]));
    setConfirmingAll(false);
    setStatusPopup({
      title: ok > 0 ? 'Stock confirmed' : 'Confirmation failed',
      message: fail.length
        ? `Confirmed ${ok} row(s).\n\nIssues:\n${fail.slice(0, 8).join('\n')}`
        : `Confirmed ${ok} incoming product row(s) into stock.`,
      tone: fail.length ? 'info' : 'success',
    });
  };

  const incomingColumns: ExcelColumn<IncomingGridRow>[] = useMemo(
    () => [
      {
        key: 'sku',
        header: 'Product SKU',
        letter: 'A',
        width: '9rem',
        getValue: (row) => row.productSku,
        columnClass: 'sms-excel-col-locked',
      },
      {
        key: 'name',
        header: 'Product Name',
        letter: 'B',
        width: '16rem',
        editable: true,
        getValue: (row) => row.productName || resolveProduct(row)?.name || '',
        render: (row, value, editing, cellKey) => (
          <ProductPickerCell
            products={products}
            displayName={String(value ?? '')}
            open={editing}
            cellKey={cellKey}
            onPick={(product) => {
              setGridRows((prev) =>
                ensureTrailingEmptyRows(
                  prev.map((item) =>
                    item.rowId !== row.rowId
                      ? item
                      : {
                          ...item,
                          productId: product.id,
                          productSku: product.sku || '',
                          productName: product.name,
                          poReference: '',
                          qtyExpected: '',
                          qtyVerified: '',
                          notes: '',
                        }
                  )
                )
              );
            }}
          />
        ),
      },
      {
        key: 'po',
        header: 'PO Reference',
        letter: 'C',
        width: '9rem',
        editable: true,
        getValue: (row) => row.poReference,
        render: (row, value, editing, cellKey) => (
          <PoReferencePickerCell
            productId={row.productId || resolveProduct(row)?.id || ''}
            productSku={row.productSku || resolveProduct(row)?.sku || ''}
            displayValue={String(value ?? '')}
            open={editing}
            cellKey={cellKey}
            onPick={(reference) => {
              const expectedQty =
                reference.qtyRemaining > 0 ? reference.qtyRemaining : reference.qtyOrdered;
              const qtyText = expectedQty > 0 ? String(expectedQty) : '';
              setGridRows((prev) =>
                ensureTrailingEmptyRows(
                  prev.map((item) =>
                    item.rowId !== row.rowId
                      ? item
                      : {
                          ...item,
                          poReference: reference.poReference || reference.poNumber,
                          qtyExpected: qtyText !== '' ? qtyText : item.qtyExpected,
                        }
                  )
                )
              );
            }}
          />
        ),
      },
      {
        key: 'expected',
        header: 'Qty Expected',
        letter: 'D',
        width: '6.5rem',
        align: 'right',
        editable: false,
        columnClass: 'sms-excel-col-locked',
        getValue: (row) => row.qtyExpected,
      },
      {
        key: 'verified',
        header: 'Qty Verified',
        letter: 'E',
        width: '6.5rem',
        align: 'right',
        editable: true,
        type: 'number',
        getValue: (row) => row.qtyVerified,
        setValue: (row, value) => ({ ...row, qtyVerified: value }),
        cellClass: (row) => {
          const expected = Number(row.qtyExpected);
          const verified = Number(row.qtyVerified);
          if (Number.isNaN(expected) || Number.isNaN(verified)) return '';
          if (Math.abs(verified - expected) <= 0.0001) return '';
          if (verified > 0 && expected > 0 && verified < expected - 0.0001) return 'is-warn';
          return 'is-warn';
        },
      },
      {
        key: 'notes',
        header: 'Notes / Reason',
        letter: 'F',
        width: '12rem',
        editable: true,
        getValue: (row) => row.notes,
        setValue: (row, value) => ({ ...row, notes: value }),
        cellClass: (row) => {
          const expected = Number(row.qtyExpected);
          const verified = Number(row.qtyVerified);
          const isShortfall = verified > 0 && expected > 0 && verified < expected - 0.0001;
          return isShortfall && !row.notes.trim() ? 'is-error' : '';
        },
      },
      {
        key: 'actions',
        header: 'Confirm',
        letter: 'G',
        width: '6.5rem',
        align: 'center',
        getValue: () => '',
        render: (row) => (
          <div className="sms-excel-actions">
            <button
              type="button"
              className="sms-excel-action-btn sms-excel-action-btn-confirm"
              disabled={processingRowId === row.rowId || confirmingAll}
              onClick={(e) => {
                e.stopPropagation();
                void handleConfirmOne(row);
              }}
              title="Confirm into stock"
            >
              {processingRowId === row.rowId ? (
                <Loader2 className="w-3.5 h-3.5 animate-spin" />
              ) : (
                <CheckCircle2 className="w-3.5 h-3.5" />
              )}
            </button>
            {row.isManual && rowHasContent(row) && (
              <button
                type="button"
                className="sms-excel-action-btn"
                disabled={processingRowId === row.rowId || confirmingAll}
                onClick={(e) => {
                  e.stopPropagation();
                  removeRow(row.rowId);
                }}
                title="Remove row"
              >
                <Trash2 className="w-3.5 h-3.5" />
              </button>
            )}
          </div>
        ),
      },
    ],
    [confirmingAll, processingRowId, productBySku, products]
  );

  return (
    <div className="sms-po-receive sms-po-receive--excel">
      <div className="sms-excel-grid-host">
        <ExcelGrid
          rows={gridRows}
          columns={incomingColumns}
          rowKey={(row) => row.rowId}
          sheetName="Incoming"
          onRowsChange={handleRowsChange}
          onCreateEmptyRow={createManualRow}
          padVisualRows={12}
          ribbonActions={
            <button
              type="button"
              disabled={confirmingAll || processingRowId !== null}
              onClick={handleConfirmAll}
              className="sms-excel-ribbon-confirm-btn"
            >
              {confirmingAll ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <CheckCircle2 className="w-4 h-4" />
              )}
              Confirm all rows
            </button>
          }
          footer={
            <span className="sms-excel-hint">
              The sheet starts empty. Pick a product, then a PO reference — Qty Expected fills from that PO line.
            </span>
          }
        />
      </div>

      {statusPopup && (
        <StatusPopup
          title={statusPopup.title}
          message={statusPopup.message}
          tone={statusPopup.tone}
          confirmLabel="Done"
          onClose={() => setStatusPopup(null)}
        />
      )}
    </div>
  );
}
