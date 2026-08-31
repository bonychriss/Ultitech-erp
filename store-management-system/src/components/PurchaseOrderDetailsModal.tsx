import React, { useState } from 'react';
import {
  BadgeCheck,
  Boxes,
  CalendarDays,
  CircleDollarSign,
  ClipboardList,
  Database,
  ExternalLink,
  Package,
  PackageCheck,
  PackageOpen,
  Paperclip,
  Truck,
  X,
} from 'lucide-react';
import type { PurchaseOrderAttachment, PurchaseOrderLine, PurchaseOrderSummary } from '../types';

interface PurchaseOrderDetailsModalProps {
  order: PurchaseOrderSummary;
  lines: PurchaseOrderLine[];
  attachments?: PurchaseOrderAttachment[];
  currencySymbol?: string;
  onClose: () => void;
}

function formatDate(iso: string): string {
  if (!iso) return 'ù';
  const d = new Date(iso.includes('T') ? iso : `${iso}T12:00:00`);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function formatMoney(symbol: string, amount: number): string {
  return `${symbol}${amount.toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function lineReceiveStatus(line: PurchaseOrderLine): string {
  if (line.receiveStatus) return line.receiveStatus;
  if (line.qtyOrdered > 0 && line.qtyRemaining <= 0) return 'Received';
  if (line.qtyReceived > 0 && line.qtyRemaining > 0) return 'Partially received';
  return 'Pending';
}

function receiveStatusClass(status: string): string {
  const key = status.toLowerCase();
  if (key === 'received') return 'sms-receive-status sms-receive-status--received';
  if (key.includes('partial')) return 'sms-receive-status sms-receive-status--partial';
  return 'sms-receive-status sms-receive-status--pending';
}

function ProductThumb({ line }: { line: PurchaseOrderLine }) {
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
        <Package className="w-5 h-5 text-indigo-400" aria-hidden="true" />
      )}
    </div>
  );
}

export default function PurchaseOrderDetailsModal({
  order,
  lines,
  attachments = [],
  currencySymbol = 'TSh',
  onClose,
}: PurchaseOrderDetailsModalProps) {
  const totalOrdered = lines.reduce((sum, line) => sum + line.qtyOrdered, 0);
  const totalReceived = lines.reduce((sum, line) => sum + line.qtyReceived, 0);
  const totalRemaining = lines.reduce((sum, line) => sum + line.qtyRemaining, 0);
  const estimatedValue = lines.reduce((sum, line) => sum + line.qtyOrdered * line.unitCost, 0);
  const receiveStatus =
    order.receiveStatus ||
    (totalOrdered > 0 && totalRemaining <= 0
      ? 'Received'
      : totalReceived > 0 && totalRemaining > 0
        ? 'Partially received'
        : 'Pending');

  return (
    <div className="sms-modal-backdrop" role="presentation" onClick={onClose}>
      <div
        className="sms-modal sms-po-details-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sms-po-details-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="sms-modal-head">
          <div className="sms-po-details-head-main">
            <div className="sms-po-details-hero-icon sms-po-details-hero-icon--violet">
              <ClipboardList className="w-5 h-5" />
            </div>
            <div>
              <div className="sms-modal-eyebrow">Purchase order details</div>
              <h2 id="sms-po-details-title" className="sms-modal-title">
                {order.poNumber || `PO #${order.id}`}
              </h2>
              <p className="sms-modal-sub">
                <Truck className="w-3.5 h-3.5 inline text-indigo-500 mr-1" />
                {order.supplierName || 'Unknown supplier'}
              </p>
            </div>
          </div>
          <button type="button" className="sms-modal-close" onClick={onClose} aria-label="Close">
            <X className="w-4 h-4" />
          </button>
        </div>

        <div className="sms-modal-body">
          <div className="sms-po-details-meta">
            <div className="sms-po-details-meta-card">
              <div className="sms-po-details-icon sms-po-details-icon--emerald">
                <BadgeCheck className="w-4 h-4" />
              </div>
              <div>
                <span className="sms-field-label">Status</span>
                <div className="sms-po-details-value">
                  <span className={receiveStatusClass(receiveStatus)}>{receiveStatus}</span>
                </div>
              </div>
            </div>
            <div className="sms-po-details-meta-card">
              <div className="sms-po-details-icon sms-po-details-icon--sky">
                <PackageOpen className="w-4 h-4" />
              </div>
              <div>
                <span className="sms-field-label">Type</span>
                <div className="sms-po-details-value">{order.purchaseType || 'ù'}</div>
              </div>
            </div>
            <div className="sms-po-details-meta-card">
              <div className="sms-po-details-icon sms-po-details-icon--amber">
                <CalendarDays className="w-4 h-4" />
              </div>
              <div>
                <span className="sms-field-label">Created</span>
                <div className="sms-po-details-value">{formatDate(order.createdAt)}</div>
              </div>
            </div>
            <div className="sms-po-details-meta-card">
              <div className="sms-po-details-icon sms-po-details-icon--violet">
                <Database className="w-4 h-4" />
              </div>
              <div>
                <span className="sms-field-label">Source</span>
                <div className="sms-po-details-value">{order.source}</div>
              </div>
            </div>
          </div>

          <div className="sms-po-details-stats">
            <div className="sms-po-details-stat">
              <div className="sms-po-details-icon sms-po-details-icon--indigo">
                <Boxes className="w-4 h-4" />
              </div>
              <div>
                <span className="sms-field-label">Lines</span>
                <strong>{lines.length || order.lineCount}</strong>
              </div>
            </div>
            <div className="sms-po-details-stat">
              <div className="sms-po-details-icon sms-po-details-icon--sky">
                <Package className="w-4 h-4" />
              </div>
              <div>
                <span className="sms-field-label">Ordered</span>
                <strong>{totalOrdered.toLocaleString()}</strong>
              </div>
            </div>
            <div className="sms-po-details-stat">
              <div className="sms-po-details-icon sms-po-details-icon--teal">
                <PackageCheck className="w-4 h-4" />
              </div>
              <div>
                <span className="sms-field-label">Received</span>
                <strong>{totalReceived.toLocaleString()}</strong>
              </div>
            </div>
            <div className="sms-po-details-stat">
              <div className="sms-po-details-icon sms-po-details-icon--amber">
                <PackageOpen className="w-4 h-4" />
              </div>
              <div>
                <span className="sms-field-label">Remaining</span>
                <strong className="text-emerald-600">{totalRemaining.toLocaleString()}</strong>
              </div>
            </div>
            <div className="sms-po-details-stat">
              <div className="sms-po-details-icon sms-po-details-icon--rose">
                <CircleDollarSign className="w-4 h-4" />
              </div>
              <div>
                <span className="sms-field-label">Est. value</span>
                <strong>{formatMoney(currencySymbol, estimatedValue)}</strong>
              </div>
            </div>
          </div>

          {attachments.length > 0 && (
            <div className="sms-linked-attachments sms-po-details-attachments">
              <span className="sms-field-label">
                <Paperclip className="w-3.5 h-3.5 inline mr-1" />
                Purchase order attachments
              </span>
              <ul className="sms-file-list">
                {attachments.map((file) => (
                  <li key={file.id}>
                    <a href={file.url} target="_blank" rel="noopener noreferrer">
                      {file.name || 'Attachment'}
                    </a>
                    {file.kind === 'invoice' ? <span className="sms-po-pill ml-2">Invoice</span> : null}
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="sms-table-wrap sms-po-details-table">
            <table className="sms-table sms-inventory-table">
              <thead>
                <tr>
                  <th className="sms-col-image">Image</th>
                  <th>Product</th>
                  <th className="text-center">Ordered</th>
                  <th className="text-center">Received</th>
                  <th className="text-center">Remaining</th>
                  <th>Status</th>
                  <th className="text-right">Unit cost</th>
                  <th className="text-right">Line value</th>
                </tr>
              </thead>
              <tbody>
                {lines.map((line) => {
                  const status = lineReceiveStatus(line);
                  return (
                  <tr key={line.lineId}>
                    <td className="sms-col-image">
                      <ProductThumb line={line} />
                    </td>
                    <td>
                      <div className="font-semibold text-slate-900">{line.productName}</div>
                      <div className="sms-product-meta">
                        <span className="sms-sku">{line.productSku || 'ó'}</span>
                      </div>
                    </td>
                    <td className="text-center font-mono">{line.qtyOrdered}</td>
                    <td className="text-center font-mono text-slate-500">{line.qtyReceived}</td>
                    <td className="text-center font-mono font-semibold text-emerald-600">
                      {line.qtyRemaining}
                    </td>
                    <td>
                      <span className={receiveStatusClass(status)}>{status}</span>
                    </td>
                    <td className="text-right font-mono">
                      {formatMoney(currencySymbol, line.unitCost)}
                    </td>
                    <td className="text-right font-mono font-semibold">
                      {formatMoney(currencySymbol, line.qtyOrdered * line.unitCost)}
                    </td>
                  </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>

        <div className="sms-modal-foot">
          <span className="sms-modal-foot-hint">
            <ExternalLink className="w-3.5 h-3.5" />
            Review only ù receive quantities are set in the form behind this popup
          </span>
          <button type="button" className="sms-desk-btn sms-desk-btn-primary" onClick={onClose}>
            Close
          </button>
        </div>
      </div>
    </div>
  );
}
