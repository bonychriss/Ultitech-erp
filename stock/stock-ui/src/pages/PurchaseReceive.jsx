import React, { useMemo, useState } from 'react';
import {
  HiOutlineArrowLeft,
  HiOutlineArrowPath,
  HiOutlineCheckCircle,
  HiOutlineEye,
  HiOutlineExclamationTriangle,
  HiOutlineInformationCircle,
} from 'react-icons/hi2';
import './products-desk.css';
import './purchase-receive.css';

function formatQty(n) {
  const v = Number(n) || 0;
  return v.toLocaleString('en', { maximumFractionDigits: 3 });
}

function ProductThumb({ src, fallback }) {
  const [imgSrc, setImgSrc] = useState(src || fallback || '');
  if (!imgSrc) {
    return (
      <div className="pr-thumb pr-thumb--empty" aria-hidden="true">
        <HiOutlineExclamationTriangle size={16} />
      </div>
    );
  }
  return (
    <div className="pr-thumb">
      <img
        src={imgSrc}
        alt=""
        loading="lazy"
        onError={() => {
          if (fallback && imgSrc !== fallback) setImgSrc(fallback);
          else setImgSrc('');
        }}
      />
    </div>
  );
}

export default function PurchaseReceive({ data }) {
  const {
    indexUrl = 'index.php',
    viewUrl = 'view_po.php',
    formAction = 'domestic_receive_process.php',
    productsUrl = '../products/index.php',
    auditUrl = 'receipt_audit.php',
    noImageUrl = '',
    po = {},
    items: initialItems = [],
    warehouses = [],
    fullyReceived = false,
  } = data;

  const [qtys, setQtys] = useState(() => {
    const init = {};
    initialItems.forEach((item) => {
      const remaining = Math.max(0, (Number(item.qty_ordered) || 0) - (Number(item.qty_received) || 0));
      init[item.id] = remaining;
    });
    return init;
  });
  const [submitting, setSubmitting] = useState(false);

  const totalReceiving = useMemo(
    () =>
      Object.values(qtys).reduce((sum, v) => {
        const n = Number(v);
        return sum + (Number.isFinite(n) && n > 0 ? n : 0);
      }, 0),
    [qtys]
  );

  const setQty = (id, remaining, raw) => {
    let n = Number(raw);
    if (!Number.isFinite(n) || n < 0) n = 0;
    if (n > remaining) n = remaining;
    setQtys((prev) => ({ ...prev, [id]: n }));
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    if (fullyReceived || totalReceiving <= 0) {
      if (window.Swal) {
        window.Swal.fire({
          icon: fullyReceived ? 'info' : 'warning',
          title: fullyReceived ? 'Already received' : 'Nothing to receive',
          text: fullyReceived
            ? 'This purchase order is fully received.'
            : 'Please enter at least one quantity to receive.',
          confirmButtonColor: '#4f46e5',
        });
      } else {
        window.alert(fullyReceived ? 'Already fully received.' : 'Enter at least one quantity.');
      }
      return;
    }

    const doSubmit = () => {
      setSubmitting(true);
      e.target.submit();
    };

    if (window.Swal) {
      window.Swal.fire({
        title: 'Confirm receipt?',
        text: `You are about to post inventory for ${formatQty(totalReceiving)} units. This updates stock levels.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#16a34a',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, post it',
        cancelButtonText: 'Review once more',
      }).then((result) => {
        if (result.isConfirmed) doSubmit();
      });
      return;
    }

    if (window.confirm(`Post inventory for ${formatQty(totalReceiving)} units?`)) {
      doSubmit();
    }
  };

  const firstItemName = initialItems[0]?.item_name || '';
  const isImport = po.purchase_type === 'import';
  const poLabel = po.po_number || `PO #${po.id || ''}`;
  const orderedLabel = po.created_at
    ? new Date(String(po.created_at).includes('T') ? po.created_at : `${po.created_at}`.replace(' ', 'T')).toLocaleDateString(
        'en-US',
        { month: 'short', day: 'numeric', year: 'numeric' }
      )
    : '-';

  return (
    <div className="prod-desk-page pr-page">
      <header className="pr-top">
        <nav className="pr-crumbs" aria-label="Breadcrumb">
          <a href={indexUrl}>Purchase orders</a>
          <span className="pr-crumbs-sep" aria-hidden="true">
            /
          </span>
          <span className="pr-crumbs-current">{poLabel}</span>
        </nav>
        <div className="pr-top-actions">
          <a href={indexUrl} className="pr-icon-link" title="Back" aria-label="Back to purchase orders">
            <HiOutlineArrowLeft size={20} />
          </a>
          <a href={`${viewUrl}?id=${po.id || ''}`} className="pr-icon-link" title="View PO" aria-label="View purchase order">
            <HiOutlineEye size={20} />
          </a>
        </div>
      </header>

      <dl className="pr-meta">
        <div className="pr-meta-cell">
          <dt>Type</dt>
          <dd>{isImport ? 'Abroad' : 'Internal'}</dd>
        </div>
        <div className="pr-meta-cell">
          <dt>Supplier</dt>
          <dd>{po.supplier_name || '-'}</dd>
        </div>
        <div className="pr-meta-cell">
          <dt>Ordered</dt>
          <dd>{orderedLabel}</dd>
        </div>
        <div className="pr-meta-cell">
          <dt>Status</dt>
          <dd>
            <span className={`pr-status${fullyReceived ? ' is-done' : ''}`}>{po.status || '-'}</span>
          </dd>
        </div>
      </dl>

      {fullyReceived ? (
        <div className="pr-alert" role="status">
          <HiOutlineCheckCircle size={18} />
          <div>
            <strong>This purchase order is already fully received.</strong> There is nothing left to post.
            <div className="pr-alert-actions">
              <a
                href={`${productsUrl}?search=${encodeURIComponent(firstItemName)}&hl=${encodeURIComponent(firstItemName)}`}
                className="prod-desk-btn prod-desk-btn-secondary"
              >
                Open in inventory
              </a>
              <a href={`${auditUrl}?po_id=${po.id || ''}`} className="prod-desk-btn prod-desk-btn-secondary">
                View receipt audit
              </a>
              <a href={indexUrl} className="prod-desk-btn prod-desk-btn-secondary">
                Back to purchase orders
              </a>
            </div>
          </div>
        </div>
      ) : null}

      <form method="post" action={formAction} className="pr-form" onSubmit={handleSubmit}>
        <input type="hidden" name="po_id" value={po.id || ''} />
        <input type="hidden" name="po_table" value={po.po_table || 'stocks_purchase_orders'} />
        <input type="hidden" name="warehouse_id" value={warehouses[0]?.id ? String(warehouses[0].id) : '1'} />
        <input type="hidden" name="notes" value="" />

        <section className="pr-section" aria-labelledby="pr-items-heading">
          <div className="pr-section-head">
            <h2 id="pr-items-heading" className="pr-section-title">
              Itemized receipt
            </h2>
            <p className="pr-section-count">
              {initialItems.length} item{initialItems.length === 1 ? '' : 's'}
              {totalReceiving > 0 ? ` - receiving ${formatQty(totalReceiving)}` : ''}
            </p>
          </div>

          {initialItems.length === 0 ? (
            <div className="pr-empty">
              <HiOutlineExclamationTriangle size={28} />
              <p>No items found for this order.</p>
            </div>
          ) : (
            <div className="pr-table-wrap">
              <table className="pr-table">
                <colgroup>
                  <col className="pr-col-img" />
                  <col className="pr-col-item" />
                  <col className="pr-col-num" />
                  <col className="pr-col-num" />
                  <col className="pr-col-num" />
                  <col className="pr-col-qty" />
                </colgroup>
                <thead>
                  <tr>
                    <th scope="col">Image</th>
                    <th scope="col">Item</th>
                    <th scope="col" className="is-num">
                      Ordered
                    </th>
                    <th scope="col" className="is-num">
                      Prev.
                    </th>
                    <th scope="col" className="is-num">
                      Left
                    </th>
                    <th scope="col" className="is-num">
                      Receive
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {initialItems.map((item) => {
                    const ordered = Number(item.qty_ordered) || 0;
                    const received = Number(item.qty_received) || 0;
                    const remaining = Math.max(0, ordered - received);
                    const img = item.image_url || noImageUrl;
                    return (
                      <tr key={item.id}>
                        <td>
                          <ProductThumb src={img} fallback={noImageUrl} />
                        </td>
                        <td>
                          <div className="pr-item-name">{item.item_name || '-'}</div>
                          <div className="pr-item-sku">{item.sku || '-'}</div>
                        </td>
                        <td className="is-num">
                          <span className="pr-qty">{formatQty(ordered)}</span>
                        </td>
                        <td className="is-num">
                          <span className="pr-qty pr-qty--muted">{formatQty(received)}</span>
                        </td>
                        <td className="is-num">
                          <span className={`pr-remain${remaining > 0 ? ' is-open' : ''}`}>
                            {formatQty(remaining)}
                          </span>
                        </td>
                        <td className="is-num">
                          <input
                            type="number"
                            name={`receive_qty[${item.id}]`}
                            className="pr-qty-input"
                            value={remaining === 0 ? 0 : qtys[item.id] ?? 0}
                            min={0}
                            max={remaining}
                            step="any"
                            disabled={remaining === 0 || fullyReceived}
                            onChange={(e) => setQty(item.id, remaining, e.target.value)}
                            onFocus={(e) => e.target.select()}
                            aria-label={`Receive quantity for ${item.item_name || item.sku || item.id}`}
                          />
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <div className="pr-footer">
          <p className="pr-tip">
            <HiOutlineInformationCircle size={16} aria-hidden="true" />
            <span>
              Quantities post to on-hand stock. Invoice details stay on the PO.
              {isImport ? ' Outdoor POs use Shipments for ETA and freight.' : ''}
            </span>
          </p>
          <button
            type="submit"
            className="prod-desk-btn prod-desk-btn-primary pr-submit"
            disabled={fullyReceived || submitting || initialItems.length === 0}
          >
            {submitting ? (
              <>
                <HiOutlineArrowPath size={16} className="pr-spin" /> Processing...
              </>
            ) : (
              <>
                <HiOutlineCheckCircle size={16} /> Post goods receipt
              </>
            )}
          </button>
        </div>
      </form>
    </div>
  );
}
