import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ArrowUpCircle,
  CloudUpload,
  Gift,
  Loader2,
  Package,
  Paperclip,
  RefreshCw,
  ShoppingBag,
  X,
} from 'lucide-react';
import { dispatchInvoice, fetchInvoiceDetail, fetchPendingInvoices, recordSampleStockOut } from '../api';
import StatusPopup, { type StatusPopupTone } from './StatusPopup';
import ExcelToolbar from './ExcelToolbar';
import ExcelGrid, { type ExcelColumn } from './ExcelGrid';
import ProductPickerCell from './ProductPickerCell';
import { exportOutgoingTemplate, parseExcelFile, parseOutgoingRows } from '../utils/excelWarehouse';
import type { InvoiceLine, PendingInvoice, Product } from '../types';

interface StoreOutgoingFormProps {
  warehouseId: number;
  products: Product[];
  preselectedProductId?: string | null;
  initialKind?: OutgoingKind;
  onRecorded: () => Promise<void>;
}

export type OutgoingKind = 'sample' | 'sold';

interface SampleGridRow {
  rowId: string;
  sku: string;
  productId: string;
  quantity: string;
  rowNotes: string;
}

let sampleGridRowSeq = 0;
function createSampleGridRow(): SampleGridRow {
  sampleGridRowSeq += 1;
  return {
    rowId: `sample-row-${sampleGridRowSeq}`,
    sku: '',
    productId: '',
    quantity: '',
    rowNotes: '',
  };
}

const SAMPLE_REASONS = [
  'Sample / promotional giveaway',
  'Demo unit',
  'Internal sample',
  'Other sample',
];

function InvoiceLineThumb({ line }: { line: InvoiceLine }) {
  const [failed, setFailed] = useState(false);
  const showImage = Boolean(line.imageUrl) && !failed;

  return (
    <div className="sms-product-thumb sms-po-details-thumb">
      {showImage ? (
        <img
          src={line.imageUrl}
          alt={line.productName}
          loading="lazy"
          onError={() => setFailed(true)}
        />
      ) : (
        <Package className="w-5 h-5 text-slate-400" aria-hidden="true" />
      )}
    </div>
  );
}

/**
 * Outgoing products form - sample giveaways or sold (invoice) releases.
 * Sample releases use the signed-in user's account signature automatically.
 */
export default function StoreOutgoingForm({
  warehouseId,
  products,
  preselectedProductId = null,
  initialKind = 'sold',
  onRecorded,
}: StoreOutgoingFormProps) {
  const [kind, setKind] = useState<OutgoingKind>(initialKind);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Sample fields — Excel-style grid rows
  const [sampleGridRows, setSampleGridRows] = useState<SampleGridRow[]>(() =>
    Array.from({ length: 15 }, () => createSampleGridRow())
  );
  const [reason, setReason] = useState(SAMPLE_REASONS[0]);
  const [notes, setNotes] = useState('');
  const [issuerName, setIssuerName] = useState('');
  const [receipts, setReceipts] = useState<File[]>([]);

  // Sold / invoice fields
  const [invoices, setInvoices] = useState<PendingInvoice[]>([]);
  const [selectedInvoiceId, setSelectedInvoiceId] = useState('');
  const [invoiceLines, setInvoiceLines] = useState<InvoiceLine[]>([]);
  const [releaseQty, setReleaseQty] = useState<Record<string, string>>({});
  const [invoiceNotes, setInvoiceNotes] = useState('');
  const [loadingInvoices, setLoadingInvoices] = useState(false);
  const [loadingInvoiceDetail, setLoadingInvoiceDetail] = useState(false);
  const [dragOver, setDragOver] = useState(false);
  const [statusPopup, setStatusPopup] = useState<{
    title: string;
    message: string;
    tone: StatusPopupTone;
  } | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const productBySku = useMemo(() => {
    const map = new Map<string, Product>();
    for (const product of products) {
      if (product.sku) map.set(product.sku.toLowerCase(), product);
    }
    return map;
  }, [products]);

  const productById = useMemo(() => new Map(products.map((p) => [p.id, p])), [products]);

  const addReceiptFiles = useCallback((fileList: FileList | File[] | null) => {
    if (!fileList) return;
    const next = Array.from(fileList).filter((f) => f && f.size > 0);
    if (next.length === 0) return;
    setReceipts((prev) => {
      const merged = [...prev];
      for (const file of next) {
        const exists = merged.some(
          (f) => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified
        );
        if (!exists) merged.push(file);
      }
      return merged;
    });
  }, []);

  const removeReceipt = useCallback((index: number) => {
    setReceipts((prev) => prev.filter((_, i) => i !== index));
  }, []);

  const attachmentDropzone = (
    <div className="sms-incoming-attachments">
      <label className="sms-field-label">
        <Paperclip className="w-3.5 h-3.5 inline mr-1" />
        Receipt / supporting attachments <span className="sms-field-optional">(optional)</span>
      </label>
      <input
        ref={fileInputRef}
        type="file"
        accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*"
        multiple
        className="sms-sr-only"
        onChange={(e) => {
          addReceiptFiles(e.target.files);
          e.target.value = '';
        }}
      />
      <button
        type="button"
        className={`sms-dropzone${dragOver ? ' is-dragover' : ''}`}
        onClick={() => fileInputRef.current?.click()}
        onDragEnter={(e) => {
          e.preventDefault();
          e.stopPropagation();
          setDragOver(true);
        }}
        onDragOver={(e) => {
          e.preventDefault();
          e.stopPropagation();
          setDragOver(true);
        }}
        onDragLeave={(e) => {
          e.preventDefault();
          e.stopPropagation();
          setDragOver(false);
        }}
        onDrop={(e) => {
          e.preventDefault();
          e.stopPropagation();
          setDragOver(false);
          addReceiptFiles(e.dataTransfer.files);
        }}
      >
        <CloudUpload className="sms-dropzone-icon" aria-hidden="true" />
        <span className="sms-dropzone-title">Upload a file</span>
        <span className="sms-dropzone-sub">Click to browse, or drag &amp; drop files here</span>
      </button>
      {receipts.length > 0 && (
        <ul className="sms-dropzone-files">
          {receipts.map((f, index) => (
            <li key={`${f.name}-${f.size}-${f.lastModified}`}>
              <span className="sms-dropzone-file-name">{f.name}</span>
              <button
                type="button"
                className="sms-dropzone-remove"
                onClick={() => removeReceipt(index)}
                aria-label={`Remove ${f.name}`}
              >
                <X className="w-3.5 h-3.5" />
              </button>
            </li>
          ))}
        </ul>
      )}
      <p className="sms-field-hint">
        Optional. First file is the primary supporting receipt. Additional files are stored with the release.
      </p>
    </div>
  );

  React.useEffect(() => {
    setKind(initialKind);
  }, [initialKind]);

  useEffect(() => {
    if (!preselectedProductId) return;
    const product = productById.get(preselectedProductId);
    if (!product) return;
    setSampleGridRows((prev) => {
      if (prev.some((row) => row.productId === product.id)) return prev;
      const emptyIdx = prev.findIndex((row) => !row.productId);
      if (emptyIdx >= 0) {
        const next = [...prev];
        next[emptyIdx] = {
          ...next[emptyIdx],
          sku: product.sku || '',
          productId: product.id,
        };
        return next;
      }
      return [...prev, { ...createSampleGridRow(), sku: product.sku || '', productId: product.id }];
    });
  }, [preselectedProductId, productById]);

  const sampleColumns: ExcelColumn<SampleGridRow>[] = useMemo(
    () => [
      {
        key: 'sku',
        header: 'Product SKU',
        letter: 'A',
        width: '9rem',
        getValue: (row) => row.sku,
        columnClass: 'sms-excel-col-locked',
      },
      {
        key: 'name',
        header: 'Product Name',
        letter: 'B',
        width: '16rem',
        editable: true,
        getValue: (row) => productById.get(row.productId)?.name ?? '',
        render: (row, value, editing, cellKey) => (
          <ProductPickerCell
            products={products}
            displayName={String(value ?? '')}
            open={editing}
            cellKey={cellKey}
            onPick={(product) => {
              setSampleGridRows((prev) =>
                prev.map((item) =>
                  item.rowId !== row.rowId
                    ? item
                    : {
                        ...item,
                        productId: product.id,
                        sku: product.sku || '',
                      }
                )
              );
            }}
          />
        ),
      },
      {
        key: 'stock',
        header: 'On Hand',
        letter: 'C',
        width: '6rem',
        align: 'right',
        getValue: (row) => {
          const product = productById.get(row.productId);
          return product ? `${product.stock} ${product.unit}` : '';
        },
      },
      {
        key: 'quantity',
        header: 'Qty Out',
        letter: 'D',
        width: '6rem',
        align: 'right',
        editable: true,
        type: 'number',
        getValue: (row) => row.quantity,
        setValue: (row, value) => ({ ...row, quantity: value }),
        cellClass: (row) => {
          const product = productById.get(row.productId);
          const qty = Number(row.quantity);
          if (!product || !row.quantity) return '';
          if (Number.isNaN(qty) || qty <= 0) return 'is-error';
          if (qty > product.stock) return 'is-error';
          return '';
        },
      },
      {
        key: 'rowNotes',
        header: 'Line Notes',
        letter: 'E',
        width: '12rem',
        editable: true,
        getValue: (row) => row.rowNotes,
        setValue: (row, value) => ({ ...row, rowNotes: value }),
      },
    ],
    [productById, products]
  );

  const refreshInvoices = React.useCallback(async () => {
    if (kind !== 'sold') return;
    try {
      const list = await fetchPendingInvoices(warehouseId, 'pending');
      setInvoices(list);
    } catch {
      // Keep current list on soft refresh failure.
    }
  }, [kind, warehouseId]);

  React.useEffect(() => {
    if (kind !== 'sold') return;
    let cancelled = false;
    const load = async () => {
      setLoadingInvoices(true);
      setError(null);
      try {
        const list = await fetchPendingInvoices(warehouseId, 'pending');
        if (!cancelled) setInvoices(list);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load invoices');
      } finally {
        if (!cancelled) setLoadingInvoices(false);
      }
    };
    load();

    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        void refreshInvoices();
      }
    };
    window.addEventListener('focus', refreshInvoices);
    document.addEventListener('visibilitychange', onVisible);
    const poll = window.setInterval(() => {
      void refreshInvoices();
    }, 15000);

    return () => {
      cancelled = true;
      window.removeEventListener('focus', refreshInvoices);
      document.removeEventListener('visibilitychange', onVisible);
      window.clearInterval(poll);
    };
  }, [kind, warehouseId, refreshInvoices]);

  React.useEffect(() => {
    if (!selectedInvoiceId) {
      setInvoiceLines([]);
      setReleaseQty({});
      return;
    }
    let cancelled = false;
    (async () => {
      setLoadingInvoiceDetail(true);
      setError(null);
      try {
        const data = await fetchInvoiceDetail(warehouseId, selectedInvoiceId);
        if (cancelled) return;
        setInvoiceLines(data.lines);
        const salesperson = (data.invoice.salespersonName || '').trim();
        if (salesperson) {
          setIssuerName(salesperson);
        }
        const defaults: Record<string, string> = {};
        for (const line of data.lines) {
          // Pre-fill with the invoiced qty; user can still reduce it.
          // Even if on hand is 0 we show the invoiced amount so they know what's expected.
          defaults[line.productId] = String(line.qtyInvoiced);
        }
        setReleaseQty(defaults);
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load invoice');
      } finally {
        if (!cancelled) setLoadingInvoiceDetail(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [selectedInvoiceId, warehouseId]);

  const handleSampleExcelImport = async (file: File) => {
    try {
      const sheetRows = await parseExcelFile(file);
      const { rows, errors } = parseOutgoingRows(sheetRows, products);
      if (errors.length > 0) {
        setStatusPopup({
          title: 'Excel import errors',
          message: errors.slice(0, 8).join('\n'),
          tone: 'error',
        });
        return;
      }
      if (rows.length === 0) {
        setStatusPopup({
          title: 'No rows found',
          message: 'The Excel file has no valid outgoing product rows.',
          tone: 'error',
        });
        return;
      }
      setSampleGridRows((prev) => {
        const filled = [...prev];
        let insertAt = 0;
        for (const row of rows) {
          const product = productById.get(row.productId);
          const entry: SampleGridRow = {
            rowId: createSampleGridRow().rowId,
            sku: product?.sku || row.productSku,
            productId: row.productId,
            quantity: row.quantity,
            rowNotes: row.notes,
          };
          const emptyIdx = filled.findIndex((r, i) => i >= insertAt && !r.productId && !r.sku.trim());
          if (emptyIdx >= 0) {
            filled[emptyIdx] = { ...filled[emptyIdx], ...entry, rowId: filled[emptyIdx].rowId };
          } else {
            filled.push(entry);
          }
          insertAt = emptyIdx >= 0 ? emptyIdx + 1 : filled.length;
        }
        while (filled.length < 15) filled.push(createSampleGridRow());
        return filled;
      });
      if (rows[0]?.reason) setReason(rows[0].reason);
      if (rows[0]?.notes) setNotes(rows[0].notes);
      setStatusPopup({
        title: 'Excel imported',
        message: `Loaded ${rows.length} product line(s). Review quantities, then submit.`,
        tone: 'success',
      });
    } catch (err) {
      setStatusPopup({
        title: 'Import failed',
        message: err instanceof Error ? err.message : 'Could not read Excel file',
        tone: 'error',
      });
    }
  };

  const resetSample = () => {
    setSampleGridRows(Array.from({ length: 15 }, () => createSampleGridRow()));
    setNotes('');
    setReceipts([]);
  };

  const handleSampleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const activeRows = sampleGridRows.filter((row) => row.productId && Number(row.quantity) > 0);
    if (activeRows.length === 0) {
      alert('Enter at least one product SKU and quantity in the sheet.');
      return;
    }
    const items: Record<string, number> = {};
    for (const row of activeRows) {
      const product = productById.get(row.productId);
      const qty = Number(row.quantity);
      if (!product) {
        alert(`Product not found for SKU "${row.sku}".`);
        return;
      }
      if (!qty || qty <= 0) {
        alert(`Enter a valid quantity for ${product.name}.`);
        return;
      }
      if (qty > product.stock) {
        alert(`Only ${product.stock} ${product.unit} available for ${product.name}.`);
        return;
      }
      items[row.productId] = (items[row.productId] ?? 0) + qty;
    }
    if (!issuerName.trim()) {
      alert('Enter the invoice / document issuer name.');
      return;
    }

    setSaving(true);
    setError(null);
    try {
      const lineNotes = activeRows
        .map((row) => row.rowNotes.trim())
        .filter(Boolean)
        .join('; ');
      await recordSampleStockOut(warehouseId, {
        items,
        reason,
        notes: [notes.trim(), lineNotes].filter(Boolean).join(' | '),
        issuerName: issuerName.trim(),
        receipts,
      });
      alert('Sample outgoing recorded.');
      resetSample();
      await onRecorded();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to record sample out');
    } finally {
      setSaving(false);
    }
  };

  const handleSoldSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedInvoiceId) {
      setStatusPopup({
        title: 'Invoice required',
        message: 'Select a sales invoice before releasing stock.',
        tone: 'error',
      });
      return;
    }
    const items: Record<string, number> = {};
    for (const [id, raw] of Object.entries(releaseQty)) {
      const qty = Number(raw);
      if (qty > 0) items[id] = qty;
    }
    if (Object.keys(items).length === 0) {
      setStatusPopup({
        title: 'Quantity required',
        message: 'Enter at least one quantity to release.',
        tone: 'error',
      });
      return;
    }
    if (!issuerName.trim()) {
      setStatusPopup({
        title: 'Issuer required',
        message: 'Enter the invoice issuer name.',
        tone: 'error',
      });
      return;
    }

    for (const [productId, qty] of Object.entries(items)) {
      const line = invoiceLines.find((entry) => entry.productId === productId);
      if (!line) continue;
      if (qty > line.qtyInvoiced) {
        setStatusPopup({
          title: 'Quantity too high',
          message: `Cannot release ${qty} of ${line.productName}. Invoiced quantity is ${line.qtyInvoiced}.`,
          tone: 'error',
        });
        return;
      }
      if (qty > line.currentStock) {
        setStatusPopup({
          title: 'Insufficient stock',
          message:
            `${line.productName} has ${line.currentStock} ${line.unit} on hand, but release quantity is ${qty}. ` +
            'Add stock or reduce the release quantity before continuing.',
          tone: 'error',
        });
        return;
      }
    }

    setSaving(true);
    setError(null);
    try {
      const result = await dispatchInvoice(warehouseId, {
        invoiceId: selectedInvoiceId,
        items,
        notes: invoiceNotes.trim(),
        supportingDocument: receipts[0] ?? null,
        invoiceDocument: receipts[1] ?? null,
        issuerName: issuerName.trim(),
        extraReceipts: receipts.slice(2),
      });
      setStatusPopup({
        title: 'Stock released',
        message: result.message + (result.deliveryNumber ? ` (${result.deliveryNumber})` : ''),
        tone: 'success',
      });
      setSelectedInvoiceId('');
      setInvoiceNotes('');
      setIssuerName('');
      setReceipts([]);
      await onRecorded();
    } catch (err) {
      setStatusPopup({
        title: 'Release failed',
        message: err instanceof Error ? err.message : 'Failed to release sold products',
        tone: 'error',
      });
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="sms-form-shell">
      <div className="sms-form-hero">
        <div>
          <h2 className="sms-form-title">Record outgoing products</h2>
          <p className="sms-form-sub">
            Log sample giveaways or sold products.
          </p>
        </div>
      </div>

      <div className="sms-form-mode-toggle" role="tablist" aria-label="Outgoing type">
        <button
          type="button"
          role="tab"
          aria-selected={kind === 'sold'}
          className={`sms-desk-btn sms-btn-rounded${kind === 'sold' ? ' sms-desk-btn-primary' : ' sms-desk-btn-secondary'}`}
          onClick={() => setKind('sold')}
        >
          <ShoppingBag className="w-4 h-4" />
          <span>Sold product (invoice)</span>
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={kind === 'sample'}
          className={`sms-desk-btn sms-btn-rounded${kind === 'sample' ? ' sms-desk-btn-primary' : ' sms-desk-btn-secondary'}`}
          onClick={() => setKind('sample')}
        >
          <Gift className="w-4 h-4" />
          <span>Sample product</span>
        </button>
      </div>

      {error && <div className="sms-desk-flash sms-desk-flash-error">{error}</div>}

      <div className="sms-form-card">
        {kind === 'sample' ? (
          <form onSubmit={handleSampleSubmit} className="sms-movement-form">
            <div className="sms-movement-form-head" style={{ padding: 0, border: 'none' }}>
              <h3 className="sms-table-title flex items-center gap-2">
                <Gift className="w-4 h-4 text-amber-600" />
                Sample outgoing
              </h3>
              <p className="sms-table-meta">
                Type product SKUs and quantities in the Excel-style sheet — same layout as the template file.
                Tab between cells, or paste rows from Excel (Ctrl+V).
              </p>
              <ExcelToolbar
                compact
                templateLabel="Download template"
                importLabel="Import Excel file"
                onExportTemplate={exportOutgoingTemplate}
                onImport={handleSampleExcelImport}
              />
            </div>

            <ExcelGrid
              rows={sampleGridRows}
              columns={sampleColumns}
              rowKey={(row) => row.rowId}
              sheetName="Outgoing"
              onRowsChange={setSampleGridRows}
              onCreateEmptyRow={createSampleGridRow}
              minEmptyRows={15}
            />

            <div className="sms-movement-grid mt-4">
              <div>
                <label className="sms-field-label">Reason *</label>
                <select required value={reason} onChange={(e) => setReason(e.target.value)} className="sms-input">
                  {SAMPLE_REASONS.map((r) => (
                    <option key={r} value={r}>
                      {r}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="sms-field-label">Issuer name *</label>
                <input
                  type="text"
                  required
                  value={issuerName}
                  onChange={(e) => setIssuerName(e.target.value)}
                  className="sms-input"
                  placeholder="Name of person issuing / authorizing"
                />
              </div>
              <div className="sms-movement-notes">
                <label className="sms-field-label">Notes</label>
                <input
                  type="text"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  className="sms-input"
                  placeholder="Recipient, campaign, or reference..."
                />
              </div>
            </div>

            {attachmentDropzone}

            <div className="sms-movement-actions">
              <button type="submit" disabled={saving} className="sms-btn-primary sms-btn-out">
                {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <ArrowUpCircle className="w-4 h-4" />}
                Record sample out
              </button>
            </div>
          </form>
        ) : (
          <form onSubmit={handleSoldSubmit} className="sms-movement-form">
            <div className="sms-movement-form-head" style={{ padding: 0, border: 'none' }}>
              <h3 className="sms-table-title flex items-center gap-2">
                <ShoppingBag className="w-4 h-4 text-indigo-600" />
                Sold product release
              </h3>
              <p className="sms-table-meta">
                Release stock against a sales invoice.
              </p>
            </div>

            <div>
              <div className="sms-field-label-row">
                <label className="sms-field-label" htmlFor="sms-sales-invoice-select">
                  Sales invoice *
                </label>
                <button
                  type="button"
                  className="sms-product-picker-select-all"
                  onClick={() => {
                    void refreshInvoices();
                  }}
                  disabled={loadingInvoices}
                >
                  <RefreshCw className={`w-3.5 h-3.5 inline mr-1${loadingInvoices ? ' animate-spin' : ''}`} />
                  Refresh
                </button>
              </div>
              {loadingInvoices && invoices.length === 0 ? (
                <div className="sms-input flex items-center gap-2 text-slate-500">
                  <Loader2 className="w-4 h-4 animate-spin" />
                  Loading invoices...
                </div>
              ) : (
                <select
                  id="sms-sales-invoice-select"
                  required
                  value={selectedInvoiceId}
                  onChange={(e) => setSelectedInvoiceId(e.target.value)}
                  onFocus={() => {
                    void refreshInvoices();
                  }}
                  className="sms-input"
                >
                  <option value="">
                    {invoices.length === 0
                      ? 'No invoices awaiting store release'
                      : 'Select invoice...'}
                  </option>
                  {invoices.map((inv) => (
                    <option key={inv.id} value={inv.id}>
                      {inv.invoiceNumber} - {inv.customerName}
                      {inv.invoiceStatus ? ` (${inv.invoiceStatus})` : ''}
                    </option>
                  ))}
                </select>
              )}
              <p className="sms-field-hint">
                Shows draft, unpaid, and paid sales invoices that have not been store-released yet. List refreshes automatically.
              </p>
            </div>

            {loadingInvoiceDetail && (
              <div className="sms-table-empty py-6">
                <Loader2 className="w-6 h-6 animate-spin text-blue-600" />
              </div>
            )}

            {!loadingInvoiceDetail && invoiceLines.length > 0 && (
              <div className="sms-table-wrap sms-po-lines-table" style={{ margin: 0 }}>
                <table className="sms-table sms-inventory-table">
                  <thead>
                    <tr>
                      <th className="sms-col-image">Image</th>
                      <th>Product</th>
                      <th className="text-center">Invoiced</th>
                      <th className="text-center">On hand</th>
                      <th className="text-center">Release qty</th>
                    </tr>
                  </thead>
                  <tbody>
                    {invoiceLines.map((line) => (
                      <tr key={line.productId}>
                        <td className="sms-col-image">
                          <InvoiceLineThumb line={line} />
                        </td>
                        <td>
                          <div className="font-semibold">{line.productName}</div>
                          <div className="sms-product-meta">
                            <span className="sms-sku">{line.productSku || '-'}</span>
                          </div>
                        </td>
                        <td className="text-center font-mono">{line.qtyInvoiced}</td>
                        <td className={`text-center font-mono${line.currentStock <= 0 ? ' text-rose-600' : ''}`}>
                          {line.currentStock}
                        </td>
                        <td className="text-center">
                          <input
                            type="number"
                            min="0"
                            max={Math.max(0, line.qtyInvoiced)}
                            value={releaseQty[line.productId] ?? ''}
                            onChange={(e) =>
                              setReleaseQty((prev) => ({ ...prev, [line.productId]: e.target.value }))
                            }
                            className="sms-input sms-po-qty-input"
                          />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <div className="sms-movement-grid">
              <div>
                <label className="sms-field-label">Invoice issuer name *</label>
                <input
                  type="text"
                  required
                  value={issuerName}
                  onChange={(e) => setIssuerName(e.target.value)}
                  className="sms-input"
                  placeholder="Name of invoice issuer"
                />
              </div>
              <div>
                <label className="sms-field-label">Release notes</label>
                <input
                  type="text"
                  value={invoiceNotes}
                  onChange={(e) => setInvoiceNotes(e.target.value)}
                  className="sms-input"
                  placeholder="Handover notes..."
                />
              </div>
            </div>

            {attachmentDropzone}

            <div className="sms-movement-actions">
              <button type="submit" disabled={saving || !selectedInvoiceId} className="sms-btn-primary sms-btn-out sms-btn-rounded">
                {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <ArrowUpCircle className="w-4 h-4" />}
                Release
              </button>
            </div>
          </form>
        )}
      </div>

      {statusPopup && (
        <StatusPopup
          title={statusPopup.title}
          message={statusPopup.message}
          tone={statusPopup.tone}
          confirmLabel={statusPopup.tone === 'success' ? 'Done' : 'OK'}
          onClose={() => setStatusPopup(null)}
        />
      )}
    </div>
  );
}
