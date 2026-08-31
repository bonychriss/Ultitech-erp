import React, { useMemo, useState } from 'react';
import { ArrowDownCircle, ArrowUpCircle, History, Inbox, Loader2, Package } from 'lucide-react';
import type { StockMovement } from '../types';
import { movementStatus } from './MovementDetail';

interface MovementsListProps {
  movements: StockMovement[];
  loading?: boolean;
  onSelect?: (movement: StockMovement) => void;
}

function formatWhen(iso: string): { day: string; time: string } {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) {
    return { day: iso || '-', time: '' };
  }
  return {
    day: d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }),
    time: d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }),
  };
}

function statusClass(status: string): string {
  const key = status.toLowerCase();
  if (key === 'received') return 'sms-receive-status sms-receive-status--received';
  if (key.includes('partial')) return 'sms-receive-status sms-receive-status--partial';
  if (key === 'shipped' || key === 'released') return 'sms-receive-status sms-receive-status--out';
  return 'sms-receive-status sms-receive-status--pending';
}

/** Strip replacement chars and normalize fancy dashes/dots from notes. */
function cleanText(value: string): string {
  return value
    .replace(/\uFFFD/g, '')
    .replace(/[\u2013\u2014\u2212]/g, '-')
    .replace(/[\u00B7\u2022\u2027]/g, '-')
    .replace(/\s{2,}/g, ' ')
    .trim();
}

function MovementThumb({ imageUrl, name }: { imageUrl?: string; name: string }) {
  const [failed, setFailed] = useState(false);
  const showImage = Boolean(imageUrl) && !failed;

  return (
    <div className="sms-product-thumb sms-product-thumb--sm" aria-hidden="true">
      {showImage ? (
        <img
          src={imageUrl}
          alt=""
          loading="lazy"
          onError={() => setFailed(true)}
        />
      ) : (
        <Package className="w-4 h-4 text-slate-400" />
      )}
      <span className="sms-sr-only">{name}</span>
    </div>
  );
}

export default function MovementsList({
  movements,
  loading = false,
  onSelect,
}: MovementsListProps) {
  const rows = useMemo(
    () => movements.filter((m) => m.movementType === 'in' || m.movementType === 'out'),
    [movements]
  );

  if (loading) {
    return (
      <div className="sms-desk-empty sms-desk-empty--compact">
        <Loader2 className="sms-desk-boot-spinner" aria-hidden="true" />
        <p className="sms-desk-empty-sub">Loading records...</p>
      </div>
    );
  }

  if (rows.length === 0) {
    return (
      <div className="sms-desk-empty sms-desk-empty--compact">
        <Inbox className="sms-desk-empty-icon" aria-hidden="true" />
        <p className="sms-desk-empty-title">No records yet</p>
        <p className="sms-desk-empty-sub">Use Receive or Outgoing to add a movement.</p>
      </div>
    );
  }

  return (
    <div className="sms-desk-table-wrap sms-desk-table-wrap--minimal">
      <table className="sms-table sms-desk-table sms-desk-table--minimal">
        <thead>
          <tr>
            <th className="sms-col-when">When</th>
            <th>Product</th>
            <th className="sms-col-type">Type</th>
            <th className="sms-col-status">Status</th>
            <th className="sms-col-qty text-right">Qty</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((m) => {
            const isIn = m.movementType === 'in';
            const when = formatWhen(m.createdAt);
            const productName = cleanText(m.productName || '') || '-';
            const meta = [m.productSku, m.categoryName]
              .map((part) => cleanText(String(part || '')))
              .filter(Boolean)
              .join(' - ');
            const notes = cleanText(m.notes || '');
            const status = movementStatus(m);

            return (
              <tr
                key={m.id}
                className={onSelect ? 'sms-desk-row-clickable' : undefined}
                tabIndex={onSelect ? 0 : undefined}
                role={onSelect ? 'button' : undefined}
                aria-label={onSelect ? `Open details for ${productName}` : undefined}
                onClick={() => onSelect?.(m)}
                onKeyDown={(e) => {
                  if (!onSelect) return;
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onSelect(m);
                  }
                }}
              >
                <td className="sms-col-when">
                  <div className="sms-when-day">{when.day}</div>
                  {when.time ? <div className="sms-when-time">{when.time}</div> : null}
                </td>
                <td className="sms-col-product">
                  <div className="sms-desk-product-cell">
                    <MovementThumb imageUrl={m.imageUrl} name={productName} />
                    <div className="sms-desk-product-copy">
                      <div className="sms-product-name">{productName}</div>
                      <div className="sms-product-meta">
                        {meta ? <span>{meta}</span> : null}
                        {notes ? (
                          <span className="sms-row-note" title={notes}>
                            {notes}
                          </span>
                        ) : null}
                      </div>
                    </div>
                  </div>
                </td>
                <td className="sms-col-type">
                  {isIn ? (
                    <span className="sms-move-chip sms-move-chip--in">
                      <ArrowDownCircle className="w-3 h-3" aria-hidden="true" />
                      In
                    </span>
                  ) : (
                    <span className="sms-move-chip sms-move-chip--out">
                      <ArrowUpCircle className="w-3 h-3" aria-hidden="true" />
                      Out
                    </span>
                  )}
                </td>
                <td className="sms-col-status">
                  <span className={statusClass(status)}>{status}</span>
                </td>
                <td className="sms-col-qty text-right">
                  <span className={`sms-qty-value${isIn ? ' is-in' : ' is-out'}`}>
                    {isIn ? '+' : '-'}
                    {m.quantity}
                  </span>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      <div className="sms-desk-results-foot">
        <History className="w-3.5 h-3.5" aria-hidden="true" />
        Store recorded and confirmed only - Click a row for details
      </div>
    </div>
  );
}
