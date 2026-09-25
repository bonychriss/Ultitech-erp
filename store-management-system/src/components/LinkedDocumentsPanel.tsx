import React, { useState } from 'react';
import { Download, ExternalLink, FileText, Paperclip } from 'lucide-react';
import type { LinkedPaymentVoucher, LinkedPaymentVoucherItem, PurchaseOrderAttachment } from '../types';

function isImageName(name: string, url = ''): boolean {
  return /\.(png|jpe?g|gif|webp|bmp)$/i.test(name) || /\.(png|jpe?g|gif|webp|bmp)(\?|$)/i.test(url);
}

function truncateName(name: string, max = 28): string {
  const s = String(name || '');
  if (s.length <= max) return s;
  const dot = s.lastIndexOf('.');
  if (dot > 0 && s.length - dot <= 5) {
    const ext = s.slice(dot);
    const base = s.slice(0, Math.max(1, max - ext.length - 1));
    return `${base}…${ext}`;
  }
  return `${s.slice(0, max - 1)}…`;
}

function formatMoney(amount: number, currency = 'TZS'): string {
  const n = Number(amount);
  if (!Number.isFinite(n)) return '—';
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: currency || 'TZS',
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(n);
  } catch {
    return `${currency || 'TZS'} ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }
}

function formatAmount(amount: number, currency: string): string {
  if (!Number.isFinite(amount) || amount <= 0) return '';
  return formatMoney(amount, currency);
}

function DocThumb({
  name,
  url,
  kindLabel,
}: {
  name: string;
  url: string;
  kindLabel: string;
}) {
  if (isImageName(name, url) && url) {
    return (
      <div className="sms-gmail-thumb sms-gmail-thumb--image">
        <img src={url} alt="" loading="lazy" />
      </div>
    );
  }
  return (
    <div className="sms-gmail-thumb sms-gmail-thumb--placeholder">
      <span className="sms-gmail-thumb-badge">{kindLabel}</span>
    </div>
  );
}

function GmailDocCard({
  name,
  sub,
  url,
  kindLabel,
  kindTone,
  onOpen,
  downloadUrl,
}: {
  name: string;
  sub?: string;
  url: string;
  kindLabel: string;
  kindTone: string;
  onOpen: () => void;
  downloadUrl?: string;
}) {
  return (
    <article className="sms-gmail-card" title={sub ? `${name}\n${sub}` : name} role="listitem">
      <button type="button" className="sms-gmail-card-hit" onClick={onOpen} aria-label={`Open ${name}`}>
        <DocThumb name={name} url={url} kindLabel={kindLabel} />
      </button>
      <div className="sms-gmail-card-foot">
        <span className={`sms-gmail-type sms-gmail-type--${kindTone}`} aria-hidden="true">
          {kindLabel}
        </span>
        <span className="sms-gmail-fname">{truncateName(name)}</span>
        {downloadUrl ? (
          <a
            href={downloadUrl}
            download
            rel="noopener noreferrer"
            className="sms-gmail-foot-action"
            title="Download"
            aria-label={`Download ${name}`}
            onClick={(e) => e.stopPropagation()}
          >
            <Download className="w-3.5 h-3.5" />
          </a>
        ) : null}
      </div>
    </article>
  );
}

function DocPreviewModal({
  title,
  url,
  isImage,
  onClose,
}: {
  title: string;
  url: string;
  isImage: boolean;
  onClose: () => void;
}) {
  return (
    <div
      className="sms-doc-preview-overlay"
      role="presentation"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="sms-doc-preview-panel" role="dialog" aria-modal="true" aria-label={title}>
        <div className="sms-doc-preview-head">
          <div className="sms-doc-preview-title">{title}</div>
          <button type="button" className="sms-doc-preview-close" onClick={onClose}>
            Close
          </button>
        </div>
        <div className="sms-doc-preview-body">
          {isImage ? (
            <img src={url} alt={title} className="sms-doc-preview-image" />
          ) : (
            <iframe src={url} title={title} className="sms-doc-preview-frame" />
          )}
        </div>
      </div>
    </div>
  );
}

function fmtPvDate(raw: string): string {
  if (!raw) return '-';
  const d = new Date(raw.includes('T') ? raw : `${raw}T12:00:00`);
  if (Number.isNaN(d.getTime())) return String(raw).slice(0, 10);
  return d.toISOString().slice(0, 10);
}

function fmtPvAmount(amount: number): string {
  const n = Number(amount) || 0;
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function VoucherDetailsBody({ voucher }: { voucher: LinkedPaymentVoucher }) {
  const items: LinkedPaymentVoucherItem[] = voucher.items ?? [];
  const currency = voucher.currency || 'TZS';
  const preparedBy = String(voucher.preparedBy || 'N/A').toUpperCase();
  const supportingQty = voucher.supportingDocuments ?? voucher.attachments.length;

  return (
    <div className="sms-pv-paper">
      <div className="sms-pv-paper-header">
        <h1 className="sms-pv-paper-title">PAYMENT VOUCHER</h1>
      </div>

      <table className="sms-pv-paper-table">
        <colgroup>
          <col style={{ width: '18%' }} />
          <col style={{ width: '32%' }} />
          <col style={{ width: '18%' }} />
          <col style={{ width: '32%' }} />
        </colgroup>
        <tbody>
          <tr>
            <td>Voucher NO. :</td>
            <td>{voucher.voucherNo || `PV #${voucher.id}`}</td>
            <td>Date:</td>
            <td>{fmtPvDate(voucher.dateCreated || '')}</td>
          </tr>
          <tr>
            <td>Payee Name:</td>
            <td>{voucher.payeeName || ''}</td>
            <td>Prepared By:</td>
            <td>{preparedBy}</td>
          </tr>
          <tr>
            <td>Description:</td>
            <td>{voucher.description || ''}</td>
            <td>Supporting Documents (Qty.)</td>
            <td>{supportingQty || '0'}</td>
          </tr>
          <tr>
            <td>Currency:</td>
            <td>{currency}</td>
            <td>Amount:</td>
            <td className="sms-pv-paper-num">{fmtPvAmount(voucher.amount)}</td>
          </tr>
        </tbody>
      </table>

      <table className="sms-pv-paper-table sms-pv-paper-table--lines">
        <colgroup>
          <col style={{ width: '18%' }} />
          <col style={{ width: '18%' }} />
          <col style={{ width: '20%' }} />
          <col style={{ width: '14%' }} />
          <col style={{ width: '30%' }} />
        </colgroup>
        <thead>
          <tr>
            <th>Payment Type</th>
            <th>Budget Type</th>
            <th>Name</th>
            <th className="sms-pv-paper-num">Amount</th>
            <th>Description</th>
          </tr>
        </thead>
        <tbody>
          {items.length === 0 ? (
            <tr>
              <td colSpan={5}>&nbsp;</td>
            </tr>
          ) : (
            items.map((item) => (
              <tr key={item.id || `${item.name}-${item.amount}`}>
                <td>{item.paymentType || ''}</td>
                <td>{item.budgetType || ''}</td>
                <td>{item.name || ''}</td>
                <td className="sms-pv-paper-num">{fmtPvAmount(item.amount)}</td>
                <td>{item.description || ''}</td>
              </tr>
            ))
          )}
        </tbody>
      </table>

      {voucher.viewUrl ? (
        <div className="sms-pv-details-foot">
          <a
            href={voucher.viewUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="sms-pv-group-open"
          >
            <ExternalLink className="w-3.5 h-3.5" />
            Full voucher page
          </a>
        </div>
      ) : null}
    </div>
  );
}

function VoucherPreviewModal({
  voucher,
  onClose,
}: {
  voucher: LinkedPaymentVoucher;
  onClose: () => void;
}) {
  const title = voucher.voucherNo || `PV #${voucher.id}`;
  return (
    <div
      className="sms-doc-preview-overlay"
      role="presentation"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div
        className="sms-doc-preview-panel sms-doc-preview-panel--voucher"
        role="dialog"
        aria-modal="true"
        aria-label={title}
      >
        <div className="sms-doc-preview-head">
          <div className="sms-doc-preview-title">{title}</div>
          <button type="button" className="sms-doc-preview-close" onClick={onClose}>
            Close
          </button>
        </div>
        <div className="sms-doc-preview-body sms-doc-preview-body--voucher">
          <VoucherDetailsBody voucher={voucher} />
        </div>
      </div>
    </div>
  );
}

interface LinkedDocumentsPanelProps {
  linkedVouchers: LinkedPaymentVoucher[];
  poAttachments: PurchaseOrderAttachment[];
}

export default function LinkedDocumentsPanel({ linkedVouchers, poAttachments }: LinkedDocumentsPanelProps) {
  const [preview, setPreview] = useState<{ title: string; url: string; isImage: boolean } | null>(null);
  const [openVoucher, setOpenVoucher] = useState<LinkedPaymentVoucher | null>(null);

  if (linkedVouchers.length === 0 && poAttachments.length === 0) {
    return null;
  }

  return (
    <div className="sms-incoming-linked-docs">
      {linkedVouchers.length > 0 && (
        <section className="sms-gmail-section">
          <div className="sms-gmail-section-head">
            <FileText className="w-3.5 h-3.5" />
            Linked payment vouchers
            <span className="sms-gmail-section-count">
              · {linkedVouchers.length} voucher{linkedVouchers.length === 1 ? '' : 's'}
            </span>
          </div>

          <div className="sms-pv-groups">
            {linkedVouchers.map((voucher) => {
              const amountLabel = formatAmount(voucher.amount, voucher.currency);
              const meta = [voucher.payeeName, voucher.status, amountLabel].filter(Boolean).join(' · ');
              const files = voucher.attachments ?? [];

              return (
                <article key={`pv-${voucher.id}`} className="sms-pv-group">
                  <header className="sms-pv-group-head">
                    <div className="sms-pv-group-title-row">
                      <span className="sms-gmail-type sms-gmail-type--doc" aria-hidden="true">
                        PV
                      </span>
                      <div className="sms-pv-group-titles">
                        <div className="sms-pv-group-no">{voucher.voucherNo || `PV #${voucher.id}`}</div>
                        {meta ? <div className="sms-pv-group-meta">{meta}</div> : null}
                      </div>
                      <button
                        type="button"
                        className="sms-pv-group-open"
                        onClick={() => setOpenVoucher(voucher)}
                      >
                        <ExternalLink className="w-3.5 h-3.5" />
                        Open voucher
                      </button>
                    </div>
                  </header>

                  {files.length > 0 ? (
                    <div className="sms-pv-group-files">
                      <div className="sms-pv-group-files-label">
                        <Paperclip className="w-3 h-3" />
                        Attachments
                        <span className="sms-gmail-section-count">
                          · {files.length} file{files.length === 1 ? '' : 's'}
                        </span>
                      </div>
                      <div className="sms-gmail-list" role="list">
                        {files.map((file) => {
                          const image = isImageName(file.name, file.url);
                          return (
                            <GmailDocCard
                              key={file.id}
                              name={file.name || 'Attachment'}
                              sub={voucher.voucherNo}
                              url={file.url}
                              kindLabel={image ? 'IMG' : file.kind === 'swift' ? 'SWIFT' : 'PDF'}
                              kindTone={image ? 'image' : 'pdf'}
                              downloadUrl={file.url}
                              onOpen={() =>
                                setPreview({
                                  title: file.name || 'Voucher attachment',
                                  url: file.url,
                                  isImage: image,
                                })
                              }
                            />
                          );
                        })}
                      </div>
                    </div>
                  ) : (
                    <div className="sms-pv-group-empty">No attachments on this voucher</div>
                  )}
                </article>
              );
            })}
          </div>
        </section>
      )}

      {poAttachments.length > 0 && (
        <section className="sms-gmail-section">
          <div className="sms-gmail-section-head">
            <Paperclip className="w-3.5 h-3.5" />
            On this PO
            <span className="sms-gmail-section-count">
              · {poAttachments.length} file{poAttachments.length === 1 ? '' : 's'}
            </span>
          </div>
          <div className="sms-gmail-list" role="list">
            {poAttachments.map((file) => {
              const image = isImageName(file.name, file.url);
              return (
                <GmailDocCard
                  key={file.id}
                  name={file.name || 'Attachment'}
                  sub={file.kind === 'invoice' ? 'Supplier invoice' : 'Purchase order'}
                  url={file.url}
                  kindLabel={image ? 'IMG' : file.kind === 'invoice' ? 'INV' : 'PDF'}
                  kindTone={image ? 'image' : 'pdf'}
                  downloadUrl={file.url}
                  onOpen={() =>
                    setPreview({
                      title: file.name || 'PO attachment',
                      url: file.url,
                      isImage: image,
                    })
                  }
                />
              );
            })}
          </div>
        </section>
      )}

      {preview && (
        <DocPreviewModal
          title={preview.title}
          url={preview.url}
          isImage={preview.isImage}
          onClose={() => setPreview(null)}
        />
      )}

      {openVoucher && (
        <VoucherPreviewModal voucher={openVoucher} onClose={() => setOpenVoucher(null)} />
      )}
    </div>
  );
}
