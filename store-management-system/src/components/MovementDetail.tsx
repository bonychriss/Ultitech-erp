import React, { useState } from 'react';
import {
  ArrowDownCircle,
  ArrowUpCircle,
  CalendarClock,
  FileText,
  Hash,
  Package,
} from 'lucide-react';
import type { Product, StockMovement } from '../types';

interface MovementDetailProps {
  movement: StockMovement;
  product?: Product | null;
}

function formatWhenParts(iso: string): { date: string; time: string } {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) {
    return { date: iso || '-', time: '' };
  }
  return {
    date: d.toLocaleDateString(undefined, {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    }),
    time: d.toLocaleTimeString(undefined, {
      hour: '2-digit',
      minute: '2-digit',
    }),
  };
}

function cleanText(value: string): string {
  return value
    .replace(/\uFFFD/g, '')
    .replace(/[\u2013\u2014\u2212]/g, '-')
    .replace(/[\u00B7\u2022\u2027\u22C5]/g, '-')
    .replace(/[^\S\r\n]+/g, ' ')
    .replace(/\s{2,}/g, ' ')
    .trim();
}

function titleCaseRef(value: string): string {
  const cleaned = cleanText(value);
  if (!cleaned) return '';
  return cleaned.charAt(0).toUpperCase() + cleaned.slice(1);
}

export function movementStatus(m: StockMovement): string {
  if (m.status) return m.status;

  const notes = m.notes || '';
  if (m.movementType === 'in') {
    const match = notes.match(/expected\s+([\d.]+)\s*,\s*verified\s+([\d.]+)/i);
    if (match) {
      const expected = Number(match[1]);
      const verified = Number(match[2]);
      if (!Number.isNaN(expected) && !Number.isNaN(verified) && Math.abs(expected - verified) > 0.0001) {
        return 'Partially received';
      }
    }
    if (/partial/i.test(notes)) return 'Partially received';
    return 'Received';
  }

  if (/shipped/i.test(notes)) return 'Shipped';
  return 'Released';
}

function statusClass(status: string): string {
  const key = status.toLowerCase();
  if (key === 'received') return 'sms-receive-status sms-receive-status--received';
  if (key.includes('partial')) return 'sms-receive-status sms-receive-status--partial';
  if (key === 'shipped' || key === 'released') return 'sms-receive-status sms-receive-status--out';
  return 'sms-receive-status sms-receive-status--pending';
}

function ProductThumb({ product, name }: { product?: Product | null; name: string }) {
  const [failed, setFailed] = useState(false);
  const showImage = Boolean(product?.imageUrl) && !failed;

  return (
    <div className="sms-movement-detail-thumb">
      {showImage ? (
        <img
          src={product?.imageUrl}
          alt={name}
          loading="lazy"
          onError={() => setFailed(true)}
        />
      ) : (
        <Package className="w-10 h-10 text-slate-400" aria-hidden="true" />
      )}
    </div>
  );
}

export default function MovementDetail({ movement, product = null }: MovementDetailProps) {
  const isIn = movement.movementType === 'in';
  const status = movementStatus(movement);
  const notes = cleanText(movement.notes || '');
  const productName = cleanText(movement.productName || '') || '-';
  const sku = cleanText(movement.productSku || '');
  const category = cleanText(movement.categoryName || '');
  const when = formatWhenParts(movement.createdAt);
  const referenceType = titleCaseRef(movement.referenceType || '');
  const referenceId = cleanText(String(movement.referenceId || ''));
  const referenceLabel = [referenceType, referenceId].filter(Boolean).join(' - ') || '-';

  return (
    <div className="sms-movement-detail">
      <section className={`sms-movement-detail-hero${isIn ? ' is-in' : ' is-out'}`}>
        <ProductThumb product={product} name={productName} />

        <div className="sms-movement-detail-hero-main">
          <div className="sms-movement-detail-kicker">
            {isIn ? (
              <span className="sms-move-chip sms-move-chip--in">
                <ArrowDownCircle className="w-3.5 h-3.5" aria-hidden="true" />
                Incoming
              </span>
            ) : (
              <span className="sms-move-chip sms-move-chip--out">
                <ArrowUpCircle className="w-3.5 h-3.5" aria-hidden="true" />
                Outgoing
              </span>
            )}
            <span className={statusClass(status)}>{status}</span>
          </div>

          <h2 className="sms-movement-detail-title">{productName}</h2>

          <div className="sms-movement-detail-meta-line">
            {sku ? <span className="sms-sku">{sku}</span> : null}
            {sku && category ? <span className="sms-movement-detail-sep">-</span> : null}
            {category ? <span>{category}</span> : null}
          </div>
        </div>

        <div className={`sms-movement-detail-qty${isIn ? ' is-in' : ' is-out'}`}>
          <span className="sms-movement-detail-qty-label">Qty</span>
          <strong>
            {isIn ? '+' : '-'}
            {movement.quantity}
          </strong>
        </div>
      </section>

      <section className="sms-movement-detail-facts" aria-label="Movement facts">
        <div className="sms-movement-detail-fact">
          <span className="sms-po-details-icon sms-po-details-icon--sky" aria-hidden="true">
            <CalendarClock className="w-4 h-4" />
          </span>
          <div className="sms-movement-detail-fact-copy">
            <span className="sms-movement-detail-fact-label">When</span>
            <strong className="sms-movement-detail-fact-value">{when.date}</strong>
            {when.time ? <span className="sms-movement-detail-fact-sub">{when.time}</span> : null}
          </div>
        </div>
        <div className="sms-movement-detail-fact">
          <span className="sms-po-details-icon sms-po-details-icon--violet" aria-hidden="true">
            <Hash className="w-4 h-4" />
          </span>
          <div className="sms-movement-detail-fact-copy">
            <span className="sms-movement-detail-fact-label">Reference</span>
            <strong className="sms-movement-detail-fact-value">{referenceLabel}</strong>
          </div>
        </div>
        <div className="sms-movement-detail-fact">
          <span
            className={`sms-po-details-icon ${isIn ? 'sms-po-details-icon--emerald' : 'sms-po-details-icon--rose'}`}
            aria-hidden="true"
          >
            {isIn ? <ArrowDownCircle className="w-4 h-4" /> : <ArrowUpCircle className="w-4 h-4" />}
          </span>
          <div className="sms-movement-detail-fact-copy">
            <span className="sms-movement-detail-fact-label">Direction</span>
            <strong className="sms-movement-detail-fact-value">{isIn ? 'Stock in' : 'Stock out'}</strong>
          </div>
        </div>
      </section>

      <section className="sms-movement-detail-notes-block">
        <span className="sms-po-details-icon sms-po-details-icon--amber" aria-hidden="true">
          <FileText className="w-4 h-4" />
        </span>
        <div className="sms-movement-detail-fact-copy">
          <span className="sms-movement-detail-fact-label">Notes</span>
          <p className="sms-movement-detail-notes">
            {notes || 'No notes recorded for this movement.'}
          </p>
        </div>
      </section>
    </div>
  );
}
