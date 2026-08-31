import React, { useCallback, useEffect, useState } from 'react';
import { CheckCircle2, ClipboardCheck, Loader2, Paperclip, XCircle } from 'lucide-react';
import { fetchPendingReceipts, verifyReceipt } from '../api';
import type { PendingReceipt } from '../types';
import StatusPopup, { type StatusPopupTone } from './StatusPopup';

interface VerifyReceiptsProps {
  warehouseId: number;
  onVerified: () => Promise<void>;
}

type PopupState = {
  title: string;
  message: string;
  tone: StatusPopupTone;
} | null;

export default function VerifyReceipts({ warehouseId, onVerified }: VerifyReceiptsProps) {
  const [receipts, setReceipts] = useState<PendingReceipt[]>([]);
  const [verifyQty, setVerifyQty] = useState<Record<string, string>>({});
  const [verifyNotes, setVerifyNotes] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [processingId, setProcessingId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [statusPopup, setStatusPopup] = useState<PopupState>(null);

  const loadReceipts = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const list = await fetchPendingReceipts(warehouseId);
      setReceipts(list);
      const defaults: Record<string, string> = {};
      for (const r of list) {
        defaults[r.id] = String(r.qtyExpected);
      }
      setVerifyQty(defaults);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load pending receipts');
    } finally {
      setLoading(false);
    }
  }, [warehouseId]);

  useEffect(() => {
    loadReceipts();
  }, [loadReceipts]);

  const handleVerify = async (receipt: PendingReceipt) => {
    const qty = Number(verifyQty[receipt.id] ?? receipt.qtyExpected);
    if (Number.isNaN(qty) || qty < 0) {
      setStatusPopup({
        title: 'Invalid quantity',
        message: 'Enter a valid verified quantity.',
        tone: 'error',
      });
      return;
    }

    const expected = receipt.qtyExpected;
    const isShortfall = qty > 0 && expected > 0 && qty < expected - 0.0001;
    const notes = (verifyNotes[receipt.id] ?? '').trim();

    if (isShortfall && notes === '') {
      setStatusPopup({
        title: 'Reason required',
        message: `Verified quantity (${qty}) is lower than expected (${expected}). Explain why before confirming. The remaining ${Number((expected - qty).toFixed(4))} will stay pending so you can fill it later.`,
        tone: 'error',
      });
      return;
    }

    setProcessingId(receipt.id);
    try {
      const result = await verifyReceipt(warehouseId, {
        receiptId: receipt.id,
        qtyVerified: qty,
        notes,
      });
      setStatusPopup({
        title: 'Confirmed into stock',
        message: result.message,
        tone: 'success',
      });
      await onVerified();
      await loadReceipts();
    } catch (err) {
      setStatusPopup({
        title: 'Confirmation failed',
        message: err instanceof Error ? err.message : 'Verification failed',
        tone: 'error',
      });
    } finally {
      setProcessingId(null);
    }
  };

  const handleReject = async (receipt: PendingReceipt) => {
    if (!confirm(`Reject receipt for ${receipt.productName}? No stock will be added.`)) {
      return;
    }
    setProcessingId(receipt.id);
    try {
      const result = await verifyReceipt(warehouseId, {
        receiptId: receipt.id,
        qtyVerified: 0,
        notes: (verifyNotes[receipt.id] || 'Rejected at store verification').trim(),
      });
      setStatusPopup({
        title: 'Receipt rejected',
        message: result.message,
        tone: 'info',
      });
      await loadReceipts();
    } catch (err) {
      setStatusPopup({
        title: 'Reject failed',
        message: err instanceof Error ? err.message : 'Failed to reject receipt',
        tone: 'error',
      });
    } finally {
      setProcessingId(null);
    }
  };

  const formatDate = (iso: string) => {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleString(undefined, {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  return (
    <div className="sms-po-receive">
      <div className="sms-movement-form-head">
        <h3 className="sms-table-title flex items-center gap-2">
          <ClipboardCheck className="w-4 h-4 text-indigo-600" />
          Confirm into stock
        </h3>
        <p className="sms-table-meta">
          Procurement records deliveries first. Count what arrived, adjust if needed, then confirm — only then is stock added to this warehouse.
          If you confirm less than expected, explain why; the remainder stays pending so you can fill it later with a reason.
        </p>
      </div>

      {error && <div className="sms-alert sms-alert-error m-4 mb-0">{error}</div>}

      {loading ? (
        <div className="sms-table-empty py-10">
          <Loader2 className="w-6 h-6 animate-spin text-blue-600" />
          <span>Loading pending receipts...</span>
        </div>
      ) : receipts.length === 0 ? (
        <div className="sms-po-empty m-4">
          No goods waiting for confirmation. When procurement records a PO delivery to this warehouse, items appear here for you to confirm into stock.
        </div>
      ) : (
        <div className="sms-verify-list">
          {receipts.map((receipt) => {
            const isProcessing = processingId === receipt.id;
            const expected = receipt.qtyExpected;
            const entered = Number(verifyQty[receipt.id] ?? expected);
            const hasDiscrepancy = !Number.isNaN(entered) && Math.abs(entered - expected) > 0.0001;
            const isShortfall =
              !Number.isNaN(entered) && entered > 0 && expected > 0 && entered < expected - 0.0001;
            const remaining = isShortfall ? Number((expected - entered).toFixed(4)) : 0;
            const notesRequired = isShortfall;
            const notesValue = verifyNotes[receipt.id] ?? '';

            return (
              <div key={receipt.id} className="sms-verify-card">
                <div className="sms-verify-card-head">
                  <div className="sms-verify-card-main">
                    <div className="sms-verify-product">
                      <div className="font-semibold text-slate-900">{receipt.productName}</div>
                      <div className="sms-product-meta mt-1">
                        <span className="sms-sku">{receipt.productSku}</span>
                        {receipt.poReference && <span>{receipt.poReference}</span>}
                      </div>
                    </div>
                    {receipt.poAttachments && receipt.poAttachments.length > 0 && (
                      <div className="sms-verify-po-files">
                        {receipt.poAttachments.map((file) => (
                          <a
                            key={file.id}
                            href={file.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="sms-incoming-file-chip"
                            title={file.name || 'Attachment'}
                          >
                            <Paperclip className="w-3 h-3 shrink-0" aria-hidden="true" />
                            {file.name || 'Attachment'}
                            {file.kind === 'invoice' ? (
                              <span className="sms-po-pill">Invoice</span>
                            ) : null}
                          </a>
                        ))}
                      </div>
                    )}
                  </div>
                  <div className="text-right text-xs text-slate-500 shrink-0">
                    <div>Recorded {formatDate(receipt.procuredAt)}</div>
                    {(receipt.qtyOriginalExpected ?? 0) > 0 && (receipt.qtyPriorReceived ?? 0) > 0 ? (
                      <div className="sms-verify-qty-summary mt-1">
                        <div>Expected: {receipt.qtyOriginalExpected}</div>
                        <div>Received: {receipt.qtyPriorReceived}</div>
                        <div className="sms-verify-rem">Rem: {expected}</div>
                      </div>
                    ) : (
                      <div className="sms-po-pill mt-1">Expected: {expected}</div>
                    )}
                  </div>
                </div>

                {receipt.procuredNotes && (
                  <p className="sms-verify-note">{receipt.procuredNotes}</p>
                )}

                {receipt.attachments && receipt.attachments.length > 0 && (
                  <div className="sms-verify-attachments">
                    <div className="sms-linked-attachments">
                      <span className="sms-field-label">
                        <Paperclip className="w-3.5 h-3.5 inline mr-1" />
                        Delivery attachments
                      </span>
                      <ul className="sms-file-list">
                        {receipt.attachments.map((file) => (
                          <li key={file.id}>
                            <a href={file.url} target="_blank" rel="noopener noreferrer">
                              {file.name || 'Attachment'}
                            </a>
                          </li>
                        ))}
                      </ul>
                    </div>
                  </div>
                )}

                <div className="sms-verify-fields">
                  <div>
                    <label className="sms-field-label">Verified quantity *</label>
                    <input
                      type="number"
                      min="0"
                      max={expected > 0 ? expected : undefined}
                      step="any"
                      value={verifyQty[receipt.id] ?? String(expected)}
                      onChange={(e) =>
                        setVerifyQty((prev) => ({ ...prev, [receipt.id]: e.target.value }))
                      }
                      className="sms-input"
                    />
                    {hasDiscrepancy && (
                      <p className="sms-verify-warn">Differs from procurement record ({expected})</p>
                    )}
                    {isShortfall && (
                      <p className="sms-verify-warn">
                        Remaining {remaining} will stay pending for later confirmation with a reason.
                      </p>
                    )}
                  </div>
                  <div>
                    <label className="sms-field-label">
                      {notesRequired ? 'Reason for lower quantity *' : 'Verification notes'}
                    </label>
                    <input
                      type="text"
                      value={notesValue}
                      onChange={(e) =>
                        setVerifyNotes((prev) => ({ ...prev, [receipt.id]: e.target.value }))
                      }
                      className={`sms-input${notesRequired && !notesValue.trim() ? ' sms-input-required' : ''}`}
                      placeholder={
                        notesRequired
                          ? 'Why is verified qty lower? (required)'
                          : 'Condition, batch, damage, etc.'
                      }
                      required={notesRequired}
                    />
                  </div>
                </div>

                <div className="sms-verify-actions">
                  <button
                    type="button"
                    disabled={isProcessing || (notesRequired && !notesValue.trim())}
                    onClick={() => handleVerify(receipt)}
                    className="sms-btn-primary sms-btn-rounded"
                  >
                    {isProcessing ? (
                      <Loader2 className="w-4 h-4 animate-spin" />
                    ) : (
                      <CheckCircle2 className="w-4 h-4" />
                    )}
                    {isShortfall ? `Confirm ${entered}` : 'Confirm'}
                  </button>
                  <button
                    type="button"
                    disabled={isProcessing}
                    onClick={() => handleReject(receipt)}
                    className="sms-btn-secondary sms-btn-reject"
                  >
                    <XCircle className="w-4 h-4" />
                    Reject
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}

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
